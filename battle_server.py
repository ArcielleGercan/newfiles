"""
FIXED Battle Server - Badge System (No Medals)
- Winner gets +1 badge count for difficulty
- Every 3 badge counts = 1 official badge (claimable)
- Challenge perfect score also gives +1 badge count

Install: pip install fastapi uvicorn websockets pymongo python-dotenv httpx
Run: python battle_server.py
"""

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from pymongo import MongoClient
from datetime import datetime, timezone
import os
from dotenv import load_dotenv
import asyncio
import random
import httpx
from typing import Dict

load_dotenv()
app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Config
MONGO_URI = os.getenv("MONGO_URI", "mongodb://localhost:27017/")
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000/api")

client = MongoClient(MONGO_URI)
db = client['starbooksWhizbee']

# Collections
battles_collection = db['battles']
player_info_collection = db['player_info']

# Active rooms and connections
rooms: Dict[str, dict] = {}
connections: Dict[str, WebSocket] = {}


async def send_to_player(user_id: str, message: dict):
    """Send message to a specific player"""
    if user_id in connections:
        try:
            ws = connections[user_id]
            await ws.send_json(message)
            print(f"  ✅ Sent {message['event']} to {user_id}")
        except Exception as e:
            print(f"  ❌ Failed to send to {user_id}: {e}")
    else:
        print(f"  ⚠️ User {user_id} not found in connections!")


async def broadcast_to_room(room_code: str, message: dict):
    """Send message to all players in a room"""
    print(f"📢 Broadcasting {message['event']} to room {room_code}")
    
    if room_code not in rooms:
        return
    
    room = rooms[room_code]
    
    # Send to host
    await send_to_player(room['host_id'], message)
    
    # Send to opponent if joined
    if room['opponent_id']:
        await send_to_player(room['opponent_id'], message)


async def fetch_questions(category: str, difficulty: str) -> list:
    """Fetch questions from Laravel API"""
    try:
        api_url = f"{LARAVEL_API_URL}/quiz/questions/{category}/{difficulty}"
        print(f"📚 Fetching questions from: {api_url}")
        
        async with httpx.AsyncClient(timeout=30.0) as client:
            response = await client.get(api_url)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('success') and data.get('questions'):
                    questions = data['questions']
                    random.shuffle(questions)
                    return questions[:10]
        
        print(f"❌ Failed to fetch questions: HTTP {response.status_code}")
        return []
    except Exception as e:
        print(f"❌ Error fetching questions: {e}")
        return []


async def save_battle_result(user_id: str, won: bool, category: str, difficulty: str,
                             score: int, questions_answered: int, correct_answers: int,
                             opponent_id: str = None, forfeit: bool = False,
                             # ✅ NEW: player info for MongoDB logging
                             player_name: str = None, player_avatar: str = None,
                             opponent_name: str = None, opponent_avatar: str = None,
                             room_code: str = None, winner_id: str = None,
                             host_score: int = None, opponent_score: int = None,
                             host_correct: int = None, opponent_correct: int = None,
                             total_questions: int = 10):
    """
    Save battle result to Laravel API + log full battle to MongoDB
    Winner gets +1 badge count for difficulty
    Every 3 badge counts = 1 official badge (claimable in player_rewards)
    """
    try:
        print(f"\n{'='*60}")
        print(f"💾 Saving Battle Result for {user_id}")
        print(f"{'='*60}")
        print(f"   Result: {'🏆 WON' if won else '❌ LOST'}")
        if forfeit:
            print(f"   ⚠️ FORFEIT: {'Won by opponent disconnect' if won else 'Lost by disconnect'}")
        print(f"   Category: {category}")
        print(f"   Difficulty: {difficulty}")
        print(f"   Score: {score}")
        print(f"   Correct: {correct_answers}/{questions_answered}")

        if won:
            print(f"   🎯 Badge Progress: +1 for {difficulty}")

        print(f"{'='*60}")

        payload = {
            'player_id': user_id,
            'category': category,
            'difficulty_level': difficulty,
            'player_score': score,
            'result': 'won' if won else 'lost',
            'battle_id': 'battle_' + str(datetime.now().timestamp()),
            'questions_answered': questions_answered,
            'correct_answers': correct_answers,
        }

        if opponent_id:
            payload['opponent_id'] = opponent_id

        print(f"📤 Sending to Laravel: {payload}")

        async with httpx.AsyncClient(timeout=10.0) as client:
            response = await client.post(
                f"{LARAVEL_API_URL}/game/save-battle-result",
                json=payload
            )

            print(f"📥 Response: {response.status_code}")

            if response.status_code == 201:
                response_data = response.json()
                print(f"✅ Battle result saved to Laravel!")

                if won:
                    badge_info = response_data.get('badge_awarded')
                    if badge_info:
                        if badge_info.get('badge_unlocked'):
                            print(f"   🎊 OFFICIAL BADGE UNLOCKED!")
                            print(f"   Badge #{badge_info['badge_number']} for {difficulty}")
                            print(f"   Status: Claimable in player_rewards")
                        else:
                            print(f"   📊 Badge Progress: {badge_info['progress']}/3")
                            print(f"   Remaining: {badge_info['remaining']} more wins")

                print(f"{'='*60}\n")
                return True
            else:
                print(f"⚠️  API Error: {response.status_code}")
                print(f"   {response.text}")
                print(f"{'='*60}\n")
                return False

    except Exception as e:
        print(f"❌ Error saving to Laravel: {e}")
        import traceback
        traceback.print_exc()
        print(f"{'='*60}\n")
        return False


def log_battle_to_mongodb(
    room_code: str,
    host_id: str, host_name: str, host_avatar: str,
    opponent_id: str, opponent_name: str, opponent_avatar: str,
    host_score: int, opponent_score: int,
    host_correct: int, opponent_correct: int,
    category: str, difficulty: str,
    winner_id: str, total_questions: int,
    forfeit: bool, started_at, finished_at
):
    """
    ✅ Log the full completed battle to MongoDB battle collection
    """
    try:
        battle_doc = {
            'room_code': room_code,
            'status': 'finished',
            'category': category,
            'difficulty': difficulty,
            'forfeit': forfeit,
            'total_questions': total_questions,

            'host': {
                'player_id': host_id,
                'player_name': host_name,
                'player_avatar': host_avatar,
                'score': host_score,
                'correct_answers': host_correct,
                'result': 'won' if winner_id == host_id else ('draw' if winner_id is None else 'lost'),
            },

            'opponent': {
                'player_id': opponent_id,
                'player_name': opponent_name,
                'player_avatar': opponent_avatar,
                'score': opponent_score,
                'correct_answers': opponent_correct,
                'result': 'won' if winner_id == opponent_id else ('draw' if winner_id is None else 'lost'),
            },

            'winner_id': winner_id,
            'scores': {
                host_id: host_score,
                opponent_id: opponent_score,
            },

            'started_at': started_at,
            'finished_at': finished_at,
            'created_at': datetime.now(timezone.utc),
        }

        result = db['battle'].insert_one(battle_doc)
        print(f"✅ Battle logged to MongoDB! ID: {result.inserted_id}")
        return str(result.inserted_id)

    except Exception as e:
        print(f"❌ Failed to log battle to MongoDB: {e}")
        return None


async def handle_player_disconnect(user_id: str, room_code: str):
    """
    Handle player disconnect/forfeit during battle
    Awards win to remaining player if game started
    """
    if room_code not in rooms:
        print(f"⚠️ Room {room_code} not found")
        return
    
    room = rooms[room_code]
    game_started = room['status'] == 'playing'
    
    print(f"\n{'='*60}")
    print(f"🚪 Player Disconnect Handler")
    print(f"{'='*60}")
    print(f"   User: {user_id}")
    print(f"   Room: {room_code}")
    print(f"   Game Started: {game_started}")
    print(f"{'='*60}")
    
    # Determine who disconnected and who remains
    if user_id == room['host_id']:
        disconnected_id = room['host_id']
        remaining_id = room['opponent_id']
        print(f"   Host disconnected")
    else:
        disconnected_id = room['opponent_id']
        remaining_id = room['host_id']
        print(f"   Opponent disconnected")
    
    # If game started, award win to remaining player
    if game_started and remaining_id:
        print(f"\n🏆 FORFEIT WIN - Awarding victory to {remaining_id}")
        
        # Get current scores and question count
        disconnected_score = room['scores'].get(disconnected_id, 0)
        remaining_score = room['scores'].get(remaining_id, 0)
        questions_answered = room['current_question'] + 1  # How many questions were answered
        
        disconnected_correct = room['correct_answers'].get(disconnected_id, 0)
        remaining_correct = room['correct_answers'].get(remaining_id, 0)
        
        print(f"   Questions answered so far: {questions_answered}")
        print(f"   Remaining player score: {remaining_score}")
        print(f"   Disconnected player score: {disconnected_score}")
        
        # Save results for both players
        print(f"\n💾 Saving forfeit results...")

        forfeit_finished_at = datetime.now(timezone.utc)

        # Remaining player WINS (gets +1 badge count)
        await save_battle_result(
            remaining_id,
            won=True,
            category=room['category'],
            difficulty=room['difficulty'],
            score=remaining_score,
            questions_answered=questions_answered,
            correct_answers=remaining_correct,
            opponent_id=disconnected_id,
            forfeit=True
        )
        
        # Disconnected player LOSES (no badge progress)
        await save_battle_result(
            disconnected_id,
            won=False,
            category=room['category'],
            difficulty=room['difficulty'],
            score=disconnected_score,
            questions_answered=questions_answered,
            correct_answers=disconnected_correct,
            opponent_id=remaining_id,
            forfeit=True
        )

        # ✅ Log forfeit battle to MongoDB
        host_id = room['host_id']
        opponent_id = room['opponent_id']
        log_battle_to_mongodb(
            room_code=room_code,
            host_id=host_id,
            host_name=room.get('host_name', 'Unknown'),
            host_avatar=room.get('host_avatar', ''),
            opponent_id=opponent_id,
            opponent_name=room.get('opponent_name', 'Unknown'),
            opponent_avatar=room.get('opponent_avatar', ''),
            host_score=room['scores'].get(host_id, 0),
            opponent_score=room['scores'].get(opponent_id, 0),
            host_correct=room['correct_answers'].get(host_id, 0),
            opponent_correct=room['correct_answers'].get(opponent_id, 0),
            category=room['category'],
            difficulty=room['difficulty'],
            winner_id=remaining_id,
            total_questions=len(room.get('questions', [])),
            forfeit=True,
            started_at=room.get('started_at'),
            finished_at=forfeit_finished_at,
        )

        print(f"✅ Forfeit results saved")
        
        # Notify remaining player
        await send_to_player(remaining_id, {
            'event': 'player_disconnected',
            'player_id': disconnected_id,
            'won_by_forfeit': True,
            'message': 'Your opponent disconnected. You win!',
        })
    else:
        print(f"ℹ️ Game not started or no remaining player, just closing room")
        
        # Notify other player if exists
        if remaining_id:
            await send_to_player(remaining_id, {
                'event': 'player_left',
                'player_id': disconnected_id,
                'message': 'Your opponent left the battle.',
            })
    
    # Clean up room
    if room_code in rooms:
        del rooms[room_code]
        print(f"🗑️ Room {room_code} deleted")
    
    print(f"{'='*60}\n")


@app.websocket("/ws/battle/{user_id}")
async def battle_websocket(websocket: WebSocket, user_id: str):
    await websocket.accept()
    connections[user_id] = websocket
    current_room = None  # Track which room this user is in
    print(f"\n✅ Player {user_id} connected")
    
    try:
        while True:
            data = await websocket.receive_json()
            event = data.get('event')
            print(f"\n📨 Event: {event} from {user_id}")
            
            # ============================================
            # CREATE ROOM
            # ============================================
            if event == 'create_room':
                room_code = data['room_code']
                current_room = room_code
                
                # Create new room
                rooms[room_code] = {
                    'room_code': room_code,
                    'host_id': user_id,
                    'host_name': data['host_name'],
                    'host_avatar': data['host_avatar'],
                    'opponent_id': None,
                    'opponent_name': None,
                    'opponent_avatar': None,
                    'category': data['category'],
                    'difficulty': data['difficulty'],
                    'status': 'waiting',
                    'questions': [],
                    'scores': {user_id: 0},
                    'correct_answers': {user_id: 0},
                    'current_question': 0,
                    'answers_this_round': set(),
                    'created_at': datetime.now(timezone.utc),
                }
                
                print(f"🎮 Room {room_code} created by {user_id}")
                
                await send_to_player(user_id, {
                    'event': 'room_created',
                    'room_code': room_code,
                    'status': 'waiting',
                })
            
            # ============================================
            # JOIN ROOM
            # ============================================
            elif event == 'join_room':
                room_code = data['room_code']
                current_room = room_code
                
                if room_code not in rooms:
                    await send_to_player(user_id, {
                        'event': 'error',
                        'message': 'Room not found',
                    })
                    continue
                
                room = rooms[room_code]
                
                if room['opponent_id'] is not None:
                    await send_to_player(user_id, {
                        'event': 'error',
                        'message': 'Room is full',
                    })
                    continue
                
                if room['status'] != 'waiting':
                    await send_to_player(user_id, {
                        'event': 'error',
                        'message': 'Game already started',
                    })
                    continue
                
                room['opponent_id'] = user_id
                room['opponent_name'] = data['player_name']
                room['opponent_avatar'] = data['player_avatar']
                room['scores'][user_id] = 0
                room['correct_answers'][user_id] = 0
                room['status'] = 'ready'
                
                print(f"👥 Player {user_id} joined room {room_code}")
                
                await send_to_player(user_id, {
                    'event': 'join_success',
                    'room_code': room_code,
                    'host_name': room['host_name'],
                    'host_avatar': room['host_avatar'],
                })
                
                await send_to_player(room['host_id'], {
                    'event': 'opponent_joined',
                    'opponent_name': data['player_name'],
                    'opponent_avatar': data['player_avatar'],
                })
            
            # ============================================
            # START GAME
            # ============================================
            elif event == 'start_game':
                room_code = data['room_code']
                
                if room_code not in rooms:
                    continue
                
                room = rooms[room_code]
                
                if user_id != room['host_id']:
                    await send_to_player(user_id, {
                        'event': 'error',
                        'message': 'Only host can start the game',
                    })
                    continue
                
                if room['opponent_id'] is None:
                    await send_to_player(user_id, {
                        'event': 'error',
                        'message': 'Waiting for opponent',
                    })
                    continue

                # Guard: prevent double-start if host taps button twice
                if room['status'] == 'playing':
                    print(f'Warning: Room {room_code} already playing, ignoring duplicate start_game')
                    continue
                
                questions = await fetch_questions(room['category'], room['difficulty'])
                
                if not questions:
                    await broadcast_to_room(room_code, {
                        'event': 'error',
                        'message': 'Failed to load questions',
                    })
                    continue
                
                room['questions'] = questions
                room['status'] = 'playing'
                room['started_at'] = datetime.now(timezone.utc)
                
                print(f"🚀 Game started in room {room_code} with {len(questions)} questions")
                
                await broadcast_to_room(room_code, {
                    'event': 'game_started',
                    'questions': questions,
                    'total_questions': len(questions),
                })
            
            # ============================================
            # SUBMIT ANSWER
            # ============================================
            elif event == 'submit_answer':
                room_code = data['room_code']
                
                if room_code not in rooms:
                    continue
                
                room = rooms[room_code]
                
                if user_id in room['answers_this_round']:
                    continue
                
                is_correct = data['is_correct']
                points = data['points']
                
                room['scores'][user_id] += points
                if is_correct:
                    room['correct_answers'][user_id] += 1
                
                room['answers_this_round'].add(user_id)
                
                print(f"📝 {user_id} answered: {'✅' if is_correct else '❌'} (+{points} pts)")
                
                await broadcast_to_room(room_code, {
                    'event': 'score_update',
                    'scores': room['scores'],
                })
                
                if len(room['answers_this_round']) == 2:
                    print(f"✅ Both players answered question {room['current_question'] + 1}")
                    
                    await broadcast_to_room(room_code, {
                        'event': 'both_answered',
                        'scores': room['scores'],
                    })
                    
                    await asyncio.sleep(3)
                    
                    room['current_question'] += 1
                    room['answers_this_round'].clear()
                    
                    if room['current_question'] >= len(room['questions']):
                        # GAME OVER
                        room['status'] = 'finished'
                        
                        host_id = room['host_id']
                        opponent_id = room['opponent_id']
                        host_score = room['scores'][host_id]
                        opponent_score = room['scores'][opponent_id]
                        
                        if host_score > opponent_score:
                            winner_id = host_id
                        elif opponent_score > host_score:
                            winner_id = opponent_id
                        else:
                            winner_id = None
                        
                        print(f"\n🏁 GAME OVER")
                        print(f"   Host ({host_id}): {host_score} pts")
                        print(f"   Opponent ({opponent_id}): {opponent_score} pts")
                        print(f"   Winner: {winner_id if winner_id else 'DRAW'}")
                        
                        print(f"\n💾 Saving results to database...")

                        finished_at = datetime.now(timezone.utc)

                        # Save host result to Laravel
                        await save_battle_result(
                            host_id,
                            won=(winner_id == host_id),
                            category=room['category'],
                            difficulty=room['difficulty'],
                            score=host_score,
                            questions_answered=len(room['questions']),
                            correct_answers=room['correct_answers'][host_id],
                            opponent_id=opponent_id,
                        )

                        # Save opponent result to Laravel
                        await save_battle_result(
                            opponent_id,
                            won=(winner_id == opponent_id),
                            category=room['category'],
                            difficulty=room['difficulty'],
                            score=opponent_score,
                            questions_answered=len(room['questions']),
                            correct_answers=room['correct_answers'][opponent_id],
                            opponent_id=host_id,
                        )

                        # ✅ Log full battle to MongoDB
                        log_battle_to_mongodb(
                            room_code=room_code,
                            host_id=host_id,
                            host_name=room.get('host_name', 'Unknown'),
                            host_avatar=room.get('host_avatar', ''),
                            opponent_id=opponent_id,
                            opponent_name=room.get('opponent_name', 'Unknown'),
                            opponent_avatar=room.get('opponent_avatar', ''),
                            host_score=host_score,
                            opponent_score=opponent_score,
                            host_correct=room['correct_answers'][host_id],
                            opponent_correct=room['correct_answers'][opponent_id],
                            category=room['category'],
                            difficulty=room['difficulty'],
                            winner_id=winner_id,
                            total_questions=len(room['questions']),
                            forfeit=False,
                            started_at=room.get('started_at'),
                            finished_at=finished_at,
                        )

                        print(f"✅ Results saved for both players")
                        
                        await broadcast_to_room(room_code, {
                            'event': 'game_over',
                            'winner_id': winner_id,
                            'scores': room['scores'],
                        })
                        
                        await asyncio.sleep(10)
                        if room_code in rooms:
                            del rooms[room_code]
                            print(f"🗑️ Room {room_code} cleaned up")
                    
                    else:
                        await broadcast_to_room(room_code, {
                            'event': 'next_question',
                            'question_index': room['current_question'],
                        })
            
            # ============================================
            # LEAVE ROOM
            # ============================================
            elif event == 'leave_room':
                room_code = data['room_code']
                
                if room_code in rooms:
                    await handle_player_disconnect(user_id, room_code)
                    current_room = None
    
    except WebSocketDisconnect:
        print(f"❌ Player {user_id} disconnected")
        
        if user_id in connections:
            del connections[user_id]
        
        # Handle disconnect in active room
        if current_room and current_room in rooms:
            await handle_player_disconnect(user_id, current_room)
    
    except Exception as e:
        print(f"❌ WebSocket error: {e}")
        import traceback
        traceback.print_exc()
        
        if user_id in connections:
            del connections[user_id]
        
        if current_room and current_room in rooms:
            await handle_player_disconnect(user_id, current_room)


@app.get("/health")
async def health_check():
    return {
        'status': 'ok',
        'active_connections': len(connections),
        'active_rooms': len(rooms),
        'rooms': [
            {
                'code': code,
                'status': room['status'],
                'players': 2 if room['opponent_id'] else 1,
            }
            for code, room in rooms.items()
        ]
    }


if __name__ == "__main__":
    import uvicorn
    print("=" * 70)
    print("🚀 Battle Server - Badge System (Fixed)")
    print("=" * 70)
    print(f"📦 MongoDB: {MONGO_URI}")
    print(f"🔗 Laravel API: {LARAVEL_API_URL}")
    print(f"🔌 WebSocket: ws://localhost:8080/ws/battle/{{user_id}}")
    print("")
    print("🎯 BADGE SYSTEM:")
    print("   • Winner: +1 badge count for difficulty")
    print("   • Every 3 badge counts = 1 official badge (claimable)")
    print("   • Loser: No badge progress")
    print("   • Draw: No badge progress")
    print("")
    print("🏆 BOTH MODES CONTRIBUTE:")
    print("   • Challenge Mode: Perfect score = +1 badge count")
    print("   • Battle Mode: Win = +1 badge count")
    print("   • Same badge system for both modes")
    print("")
    print("⚠️  FORFEIT HANDLING:")
    print("   • If player disconnects during game: Opponent wins")
    print("   • Results saved automatically")
    print("   • Winner gets badge progress")
    print("=" * 70)
    uvicorn.run(app, host="0.0.0.0", port=8080)
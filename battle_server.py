"""
battle_server.py  — complete fixed server
Run with:  uvicorn battle_server:app --host 0.0.0.0 --port 80
or behind nginx with:  uvicorn battle_server:app --uds /run/battle.sock
"""

import asyncio
import json
import random
import string
import traceback
from typing import Optional

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware

import httpx                         # pip install httpx
from dotenv import load_dotenv       # pip install python-dotenv
import os

load_dotenv()

LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://localhost:8000/api")

app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── In-memory state ──────────────────────────────────────────────────────────
# rooms[room_code] = {
#   host_id, host_name, host_avatar,
#   opponent_id, opponent_name, opponent_avatar,
#   category, difficulty,
#   status: 'waiting' | 'ready' | 'playing' | 'finished',
#   scores: {user_id: int},
#   correct_answers: {user_id: int},
#   questions: [...],
#   answers_this_round: {user_id: bool},
#   current_question_index: int,
# }
rooms: dict = {}

# connected_players[user_id] = WebSocket
connected_players: dict[str, WebSocket] = {}


# ── Helpers ──────────────────────────────────────────────────────────────────

async def send_to_player(user_id: str, data: dict) -> None:
    ws = connected_players.get(user_id)
    if ws:
        try:
            await ws.send_text(json.dumps(data))
        except Exception as e:
            print(f"❌ send_to_player({user_id}) failed: {e}")


async def fetch_questions(category: str, difficulty: str) -> list:
    """Fetch 10 questions from the Laravel API."""
    url = f"{LARAVEL_API_URL}/battle-questions"
    params = {"category": category, "difficulty": difficulty, "limit": 10}
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.get(url, params=params)
            resp.raise_for_status()
            data = resp.json()
            # Accept either {"questions": [...]} or a bare list
            if isinstance(data, list):
                return data
            return data.get("questions", [])
    except Exception as e:
        print(f"❌ fetch_questions failed: {e}")
        return []


def _room_for_user(user_id: str) -> Optional[str]:
    """Return the room_code the user is currently in, or None."""
    for code, room in rooms.items():
        if room["host_id"] == user_id or room["opponent_id"] == user_id:
            return code
    return None


async def _handle_disconnect(user_id: str) -> None:
    """Notify the opponent when a player drops the connection."""
    room_code = _room_for_user(user_id)
    if not room_code:
        return
    room = rooms.get(room_code)
    if room is None or room["status"] == "finished":
        rooms.pop(room_code, None)
        return

    opponent_id = (
        room["opponent_id"]
        if room["host_id"] == user_id
        else room["host_id"]
    )

    # Notify opponent BEFORE removing the room
    if opponent_id and opponent_id in connected_players:
        await send_to_player(opponent_id, {"event": "player_disconnected"})

    # Clean up the room
    rooms.pop(room_code, None)
    print(f"🧹 Room {room_code} cleaned up after disconnect of {user_id}")


async def _advance_round(room: dict, room_code: str) -> None:
    """Called in a background task after both players answer.
    Waits for the feedback delay then sends next_question or game_over."""
    await asyncio.sleep(2.5)   # let clients show answer feedback

    # Room may have been cleaned up (e.g. a player disconnected mid-sleep)
    if room_code not in rooms:
        return

    if room["current_question_index"] < len(room["questions"]):
        next_q = {"event": "next_question"}
        await send_to_player(room["host_id"],     next_q)
        await send_to_player(room["opponent_id"], next_q)
    else:
        # ── GAME OVER ──
        room["status"] = "finished"
        h_id = room["host_id"]
        o_id = room["opponent_id"]
        h_score = room["scores"].get(h_id, 0)
        o_score = room["scores"].get(o_id, 0)

        if h_score > o_score:
            winner_id = h_id
        elif o_score > h_score:
            winner_id = o_id
        else:
            winner_id = None   # draw

        game_over = {
            "event":     "game_over",
            "winner_id": winner_id,
            "scores":    room["scores"],
        }
        await send_to_player(h_id, game_over)
        await send_to_player(o_id, game_over)
        print(f"🏁 Game over: {room_code}  winner={winner_id}")


# ── WebSocket endpoint ───────────────────────────────────────────────────────

@app.websocket("/ws/battle/{user_id}")
async def battle_websocket(websocket: WebSocket, user_id: str):
    await websocket.accept()
    connected_players[user_id] = websocket
    print(f"✅ Player connected: {user_id}  (total: {len(connected_players)})")

    current_room: Optional[str] = None   # tracks which room this socket joined

    try:
        while True:
            raw = await websocket.receive_text()
            try:
                data = json.loads(raw)
            except json.JSONDecodeError:
                await send_to_player(user_id, {"event": "error", "message": "Invalid JSON"})
                continue

            event = data.get("event", "")
            print(f"📨 [{user_id}] {event}")

            # ── CREATE ROOM ──────────────────────────────────────────────────
            if event == "create_room":
                room_code = data["room_code"]

                # Clean up any previous room this user was hosting
                old_code = _room_for_user(user_id)
                if old_code and old_code != room_code:
                    rooms.pop(old_code, None)

                current_room = room_code

                rooms[room_code] = {
                    "host_id":      user_id,
                    "host_name":    data["host_name"],
                    "host_avatar":  data["host_avatar"],
                    "category":     data["category"],
                    "difficulty":   data["difficulty"],
                    "opponent_id":     None,
                    "opponent_name":   None,
                    "opponent_avatar": None,
                    "status":   "waiting",
                    "scores":   {user_id: 0},
                    "correct_answers": {user_id: 0},
                    "questions": [],
                    "answers_this_round": {},
                    "current_question_index": 0,
                }
                await send_to_player(user_id, {
                    "event":     "room_created",
                    "room_code": room_code,
                })
                print(f"🏠 Room created: {room_code} by {user_id}")

            # ── JOIN ROOM ────────────────────────────────────────────────────
            elif event == "join_room":
                room_code = data["room_code"]

                if room_code not in rooms:
                    await send_to_player(user_id, {
                        "event":   "error",
                        "message": "Room not found",
                    })
                    continue

                room = rooms[room_code]

                # Guard: can't join your own room
                if room["host_id"] == user_id:
                    await send_to_player(user_id, {
                        "event":   "error",
                        "message": "You cannot join your own room",
                    })
                    continue

                if room["opponent_id"] is not None:
                    await send_to_player(user_id, {
                        "event":   "error",
                        "message": "Room is full",
                    })
                    continue

                if room["status"] != "waiting":
                    await send_to_player(user_id, {
                        "event":   "error",
                        "message": "Game already started",
                    })
                    continue

                room["opponent_id"]     = user_id
                room["opponent_name"]   = data["player_name"]
                room["opponent_avatar"] = data["player_avatar"]
                room["scores"][user_id] = 0
                room["correct_answers"][user_id] = 0
                room["status"] = "ready"
                current_room = room_code   # only set after all checks pass

                print(f"👥 Player {user_id} joined room {room_code}")

                # Tell the joiner the host's settings so the UI is consistent
                await send_to_player(user_id, {
                    "event":       "join_success",
                    "room_code":   room_code,
                    "host_name":   room["host_name"],
                    "host_avatar": room["host_avatar"],
                    "category":    room["category"],
                    "difficulty":  room["difficulty"],
                })

                # Tell the host someone joined
                await send_to_player(room["host_id"], {
                    "event":           "opponent_joined",
                    "opponent_name":   data["player_name"],
                    "opponent_avatar": data["player_avatar"],
                })

            # ── START GAME ───────────────────────────────────────────────────
            elif event == "start_game":
                room_code = data.get("room_code", current_room)
                if not room_code or room_code not in rooms:
                    await send_to_player(user_id, {
                        "event": "error", "message": "Room not found"
                    })
                    continue

                room = rooms[room_code]

                # Only the host may start
                if room["host_id"] != user_id:
                    await send_to_player(user_id, {
                        "event": "error", "message": "Only the host can start the game"
                    })
                    continue

                if room["status"] != "ready":
                    await send_to_player(user_id, {
                        "event": "error", "message": "Opponent has not joined yet"
                    })
                    continue

                questions = await fetch_questions(room["category"], room["difficulty"])
                if not questions:
                    await send_to_player(user_id, {
                        "event": "error", "message": "Failed to load questions. Try again."
                    })
                    continue

                room["questions"] = questions
                room["status"] = "playing"
                room["current_question_index"] = 0
                room["answers_this_round"] = {}

                payload = {
                    "event":     "game_started",
                    "questions": questions,
                }
                await send_to_player(room["host_id"],     payload)
                await send_to_player(room["opponent_id"], payload)
                print(f"🎮 Game started: {room_code}  ({len(questions)} questions)")

            # ── SUBMIT ANSWER ────────────────────────────────────────────────
            elif event == "submit_answer":
                room_code = data.get("room_code", current_room)
                if not room_code or room_code not in rooms:
                    continue

                room = rooms[room_code]
                if room["status"] != "playing":
                    continue

                is_correct    = bool(data.get("is_correct", False))
                points        = int(data.get("points", 0))
                question_idx  = int(data.get("question_index", 0))

                # Clamp points to valid range [0, 15]
                points = max(0, min(15, points))

                # Record the answer (guard against double-submission)
                if user_id not in room["answers_this_round"]:
                    room["scores"][user_id] = room["scores"].get(user_id, 0) + points
                    if is_correct:
                        room["correct_answers"][user_id] = \
                            room["correct_answers"].get(user_id, 0) + 1
                    room["answers_this_round"][user_id] = is_correct

                # Broadcast updated scores to both players
                score_update = {
                    "event":  "score_update",
                    "scores": room["scores"],
                }
                await send_to_player(room["host_id"],     score_update)
                await send_to_player(room["opponent_id"], score_update)

                # Both players have answered this round?
                both_ids = {room["host_id"], room["opponent_id"]}
                answered = set(room["answers_this_round"].keys())
                if both_ids <= answered:
                    both_answered = {
                        "event":  "both_answered",
                        "scores": room["scores"],
                    }
                    await send_to_player(room["host_id"],     both_answered)
                    await send_to_player(room["opponent_id"], both_answered)

                    room["current_question_index"] += 1
                    room["answers_this_round"] = {}

                    # Run the delay + next-step dispatch in a background task so
                    # the message-receive loop is not blocked for 2.5 s.
                    asyncio.create_task(
                        _advance_round(room, room_code)
                    )

            # ── LEAVE ROOM ───────────────────────────────────────────────────
            elif event == "leave_room":
                room_code = data.get("room_code", current_room)
                if not room_code or room_code not in rooms:
                    continue

                room = rooms[room_code]
                opponent_id = (
                    room["opponent_id"]
                    if room["host_id"] == user_id
                    else room["host_id"]
                )
                if opponent_id and opponent_id in connected_players:
                    await send_to_player(opponent_id, {"event": "player_left"})

                rooms.pop(room_code, None)
                current_room = None
                print(f"🚪 {user_id} left room {room_code}")

            else:
                print(f"⚠️ Unknown event '{event}' from {user_id}")

    except WebSocketDisconnect:
        print(f"🔌 Player disconnected: {user_id}")
    except Exception:
        print(f"❌ Unhandled error for {user_id}:")
        traceback.print_exc()
    finally:
        connected_players.pop(user_id, None)
        await _handle_disconnect(user_id)
        print(f"👋 Player removed: {user_id}  (total: {len(connected_players)})")
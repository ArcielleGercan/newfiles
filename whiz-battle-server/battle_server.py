"""
battle_server.py  — multi-device patch
Only the join_room handler is changed; everything else is identical.
Replace the old join_room block (look for `elif event == 'join_room':`)
with this block.
"""

# ============================================
# JOIN ROOM  (replace the whole elif block)
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

    room['opponent_id']     = user_id
    room['opponent_name']   = data['player_name']
    room['opponent_avatar'] = data['player_avatar']
    room['scores'][user_id] = 0
    room['correct_answers'][user_id] = 0
    room['status'] = 'ready'

    print(f"👥 Player {user_id} joined room {room_code}")

    # ✅ Send category + difficulty so the joiner uses the HOST's settings,
    #    not whatever the joiner had selected on their own setup page.
    await send_to_player(user_id, {
        'event':        'join_success',
        'room_code':    room_code,
        'host_name':    room['host_name'],
        'host_avatar':  room['host_avatar'],
        'category':     room['category'],    # ← new
        'difficulty':   room['difficulty'],  # ← new
    })

    await send_to_player(room['host_id'], {
        'event':           'opponent_joined',
        'opponent_name':   data['player_name'],
        'opponent_avatar': data['player_avatar'],
    })
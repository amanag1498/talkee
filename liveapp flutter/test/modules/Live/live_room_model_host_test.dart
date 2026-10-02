import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/modules/Live/models/live_room_model.dart';

void main() {
  test('audio room join response keeps host identity and role', () {
    final room = LiveRoomModel.fromResponse({
      'ok': true,
      'room': 'audio-room-1',
      'room_type': 'audio',
      'role': 'host',
      'host_id': 42,
      'host_name': 'Room Host',
    });

    expect(room.roomId, 'audio-room-1');
    expect(room.hostUserId, 42);
    expect(room.hostName, 'Room Host');
    expect(room.role, 'host');
  });

  test('audio room start response keeps nested host identity', () {
    final room = LiveRoomModel.fromResponse({
      'ok': true,
      'role': 'host',
      'room': {
        'room_id': 'audio-room-2',
        'room_type': 'audio',
        'host_id': 73,
        'host_name': 'Stage Name',
      },
    });

    expect(room.hostUserId, 73);
    expect(room.hostName, 'Stage Name');
    expect(room.role, 'host');
  });
}

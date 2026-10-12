import 'package:cross_file/cross_file.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

class ChatVoiceNote {
  const ChatVoiceNote({
    required this.bytes,
    required this.filename,
    required this.mime,
  });

  final Uint8List bytes;
  final String filename;
  final String mime;
}

/// Thin wrapper around [AudioRecorder] so chat can start/stop a voice note.
class ChatVoiceRecorder {
  ChatVoiceRecorder({AudioRecorder? recorder})
    : _recorder = recorder ?? AudioRecorder();

  final AudioRecorder _recorder;
  String _extension = 'wav';
  String _mime = 'audio/wav';

  Future<bool> ensurePermission() => _recorder.hasPermission();

  Future<void> start() async {
    final format = await _resolveFormat();
    _extension = format.extension;
    _mime = format.mime;
    final path = kIsWeb
        ? 'voice-note.$_extension'
        : '${(await getTemporaryDirectory()).path}/voice_${DateTime.now().millisecondsSinceEpoch}.$_extension';
    await _recorder.start(
      RecordConfig(encoder: format.encoder, numChannels: 1, sampleRate: 16000),
      path: path,
    );
  }

  Future<ChatVoiceNote?> stop() async {
    final path = await _recorder.stop();
    if (path == null || path.isEmpty) return null;
    final bytes = await XFile(path).readAsBytes();
    if (bytes.isEmpty) return null;
    return ChatVoiceNote(
      bytes: bytes,
      filename: 'voice-note.$_extension',
      mime: _mime,
    );
  }

  Future<void> cancel() async {
    if (await _recorder.isRecording()) {
      await _recorder.cancel();
    }
  }

  Future<void> pause() => _recorder.pause();

  Future<void> resume() => _recorder.resume();

  Stream<double> amplitudeStream(Duration interval) {
    return _recorder
        .onAmplitudeChanged(interval)
        .map((amplitude) => amplitude.current);
  }

  Future<void> dispose() => _recorder.dispose();

  Future<({AudioEncoder encoder, String extension, String mime})>
  _resolveFormat() async {
    if (await _recorder.isEncoderSupported(AudioEncoder.wav)) {
      return (encoder: AudioEncoder.wav, extension: 'wav', mime: 'audio/wav');
    }
    if (await _recorder.isEncoderSupported(AudioEncoder.aacLc)) {
      return (encoder: AudioEncoder.aacLc, extension: 'm4a', mime: 'audio/mp4');
    }
    throw StateError('no-encoder');
  }
}

import 'package:uuid/uuid.dart';

String newIdempotencyKey() => const Uuid().v4();

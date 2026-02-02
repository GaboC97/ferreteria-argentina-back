<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nuevo mensaje de contacto</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Nuevo mensaje de contacto</h2>

    <p><strong>Nombre:</strong> {{ $datos['nombre'] }}</p>
    <p><strong>Email:</strong> {{ $datos['email'] }}</p>
    @if(!empty($datos['telefono']))
        <p><strong>Teléfono:</strong> {{ $datos['telefono'] }}</p>
    @endif
    <p><strong>Asunto:</strong> {{ $datos['asunto'] }}</p>

    <hr>

    <p><strong>Mensaje:</strong></p>
    <p>{{ $datos['mensaje'] }}</p>
</body>
</html>

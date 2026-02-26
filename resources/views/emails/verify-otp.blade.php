<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Código de verificación</title>
</head>

<body style="margin:0; padding:0; background:#f1f5f9;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f1f5f9; padding:24px 0;">
    <tr>
      <td align="center">

        <!-- Container -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="620"
          style="width:620px; max-width:94%; background:#ffffff; border:1px solid #e2e8f0; border-radius:18px; overflow:hidden; box-shadow:0 10px 30px rgba(15,23,42,0.08);">

          <!-- Header -->
          <tr>
            <td style="padding:22px 24px; background:linear-gradient(180deg, #0f172a 0%, #111827 100%);">
              <div style="font-family: Arial, sans-serif; color:#ffffff;">
                <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.85; font-weight:700;">
                  Ferrear · Verificación
                </div>
                <div style="font-size:22px; font-weight:800; margin-top:6px;">
                  Código de verificación
                </div>
                <div style="font-size:13px; opacity:0.85; margin-top:6px;">
                  Usá este código para verificar tu cuenta.
                </div>
              </div>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:22px 24px 8px 24px; font-family: Arial, sans-serif; color:#0f172a;">

              <!-- Saludo -->
              <div style="font-size:15px; font-weight:700; margin-bottom:16px;">
                Hola {{ $name }},
              </div>

              <div style="font-size:14px; color:#334155; line-height:1.6; margin-bottom:20px;">
                Recibimos una solicitud para verificar tu dirección de email. Ingresá el siguiente código en la app para continuar:
              </div>

              <!-- Código OTP -->
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td align="center" style="padding:20px 0;">
                    <div style="display:inline-block; background:#f0f9ff; border:2px solid #0284c7; border-radius:14px; padding:18px 40px;">
                      <div style="font-size:36px; font-weight:800; letter-spacing:12px; color:#0369a1; font-family: 'Courier New', monospace;">
                        {{ $code }}
                      </div>
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Aviso de vencimiento -->
              <div style="margin-top:8px; padding:14px; border-radius:14px; background:#fef3c7; border:1px solid #fbbf24;">
                <div style="font-size:13px; color:#92400e; font-weight:700; line-height:1.5; text-align:center;">
                  Este código vence en 10 minutos.
                </div>
              </div>

              <!-- Seguridad -->
              <div style="margin-top:12px; padding:14px; border-radius:14px; background:#f8fafc; border:1px dashed #cbd5e1;">
                <div style="font-size:13px; color:#334155; font-weight:700; line-height:1.5;">
                  Si no solicitaste este código, podés ignorar este email. Tu cuenta no se verá afectada.
                </div>
              </div>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:14px 24px 18px 24px; font-family: Arial, sans-serif; color:#64748b;">
              <div style="border-top:1px solid #e2e8f0; padding-top:12px; font-size:12px; line-height:1.5;">
                Este mensaje fue enviado automáticamente. Por favor no respondas a este email.<br/>
                <span style="opacity:0.85;">&copy; {{ date('Y') }} Ferrear - Todos los derechos reservados.</span>
              </div>
            </td>
          </tr>

        </table>
        <!-- /Container -->

      </td>
    </tr>
  </table>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Nueva postulación laboral</title>
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
                  Ferretería · RRHH
                </div>
                <div style="font-size:22px; font-weight:800; margin-top:6px;">
                  Nueva postulación laboral
                </div>
                <div style="font-size:13px; opacity:0.85; margin-top:6px;">
                  Se recibió desde el formulario web de postulaciones.
                </div>
              </div>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:22px 24px 8px 24px; font-family: Arial, sans-serif; color:#0f172a;">

              <!-- Puesto badge -->
              <div style="margin-bottom:14px;">
                <span style="display:inline-block; font-size:12px; font-weight:800; color:#0369a1; background:#e0f2fe; border:1px solid #bae6fd; padding:6px 10px; border-radius:999px;">
                  Puesto: {{ $datos['puesto'] }}
                </span>
              </div>

              <!-- Datos -->
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                style="border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;">

                <tr>
                  <td style="padding:14px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;">
                      Datos del postulante
                    </div>
                  </td>
                </tr>

                <tr>
                  <td style="padding:14px 14px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                      <tr>
                        <td style="padding:8px 0; width:140px; color:#64748b; font-size:13px; font-weight:700;">
                          Nombre
                        </td>
                        <td style="padding:8px 0; font-size:14px; font-weight:800; color:#0f172a;">
                          {{ $datos['nombre'] }}
                        </td>
                      </tr>

                      <tr>
                        <td style="padding:8px 0; width:140px; color:#64748b; font-size:13px; font-weight:700;">
                          Email
                        </td>
                        <td style="padding:8px 0; font-size:14px; font-weight:800;">
                          <a href="mailto:{{ $datos['email'] }}" style="color:#0284c7; text-decoration:none;">
                            {{ $datos['email'] }}
                          </a>
                        </td>
                      </tr>

                      <tr>
                        <td style="padding:8px 0; width:140px; color:#64748b; font-size:13px; font-weight:700;">
                          Teléfono
                        </td>
                        <td style="padding:8px 0; font-size:14px; font-weight:800; color:#0f172a;">
                          <a href="tel:{{ $datos['telefono'] }}" style="color:#0f172a; text-decoration:none;">
                            {{ $datos['telefono'] }}
                          </a>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Mensaje -->
              @if(!empty($datos['mensaje']))
              <div style="margin-top:16px;">
                <div style="font-size:13px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:8px;">
                  Mensaje
                </div>

                <div style="border:1px solid #e2e8f0; background:#ffffff; border-radius:14px; padding:14px; color:#0f172a; font-size:14px; line-height:1.65;">
                  {!! nl2br(e($datos['mensaje'])) !!}
                </div>
              </div>
              @endif

              <!-- CV notice -->
              <div style="margin-top:16px; padding:14px; border-radius:14px; background:#f0fdf4; border:1px dashed #86efac;">
                <div style="font-size:13px; color:#166534; font-weight:700; line-height:1.5;">
                  El CV del postulante se encuentra adjunto a este email.
                </div>
              </div>

              <!-- Tip -->
              <div style="margin-top:12px; padding:14px; border-radius:14px; background:#f8fafc; border:1px dashed #cbd5e1;">
                <div style="font-size:13px; color:#334155; font-weight:700; line-height:1.5;">
                  Tip: Podés responder este email directamente (queda como <strong>reply-to</strong> el correo del postulante).
                </div>
              </div>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:14px 24px 18px 24px; font-family: Arial, sans-serif; color:#64748b;">
              <div style="border-top:1px solid #e2e8f0; padding-top:12px; font-size:12px; line-height:1.5;">
                Este mensaje fue enviado automáticamente desde el formulario de postulaciones.<br/>
                <span style="opacity:0.85;">Si no esperabas este mensaje, revisá la configuración del sitio.</span>
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

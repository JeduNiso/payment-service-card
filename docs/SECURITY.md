# Seguridad de CyberSource

## Principios

- Nunca guardar PAN completo.
- Nunca guardar CVV.
- Nunca registrar payloads completos del gateway en logs.
- Usar variables de entorno para credenciales.
- Redactar información sensible antes de registrar errores.

## Reglas

- Los logs solo deben incluir booking code, referencia, status y códigos de error.
- Los datos de tarjeta solo deben estar en memoria durante el request actual.
- Los datos de tarjeta no deben persistirse en la base de datos legacy.
- Los secretos del gateway deben ir en `.env` y no en el repositorio.

## Riesgos actuales detectados

El controlador existente estaba guardando PAN, expiración y CVV dentro de `cybersource_payment_data` en la reserva; esto debe corregirse en una fase posterior sin tocar el esquema legacy. La primera implementación debe mantener el flujo funcional y remover la persistencia innecesaria de campos sensibles.

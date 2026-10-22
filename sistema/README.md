# sistema/ · ASO

Usuarios del servidor y acceso por SSH.

- `usuarios/ayoub.pub`: clave pública de Ayoub. **Pendientes:** las de Younes,
  Nico y el profesorado (una `.pub` por persona; el nombre del fichero es el del
  usuario que se crea en el servidor).
- `ssh/99-sede-hardening.conf`: endurecimiento de sshd (solo acceso por clave,
  sesiones inactivas cortadas, root solo con clave).

Lo instala el **paso 1** de `instalar.sh`: crea al usuario por cada `.pub`, le
instala su `authorized_keys` y, solo cuando ya existe alguna clave, aplica el
endurecimiento y recarga sshd (validando antes con `sshd -t`: un fichero roto
no puede dejarnos fuera del servidor).

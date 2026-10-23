# sistema/ · ASO

Usuarios del servidor y acceso por SSH.

- `usuarios/ayoub.pub`: clave pública de Ayoub. **Pendientes:** las de Younes,
  Nico y el profesorado (una `.pub` por persona; el nombre del fichero es el del
  usuario que se crea en el servidor).
- `ssh/00-sede-hardening.conf`: endurecimiento de sshd (solo acceso por clave,
  sesiones inactivas cortadas, root solo con clave). Empieza por `00-` porque
  sshd se queda con el primer valor que lee y Ubuntu trae `50-cloud-init.conf`
  con `PasswordAuthentication yes`.

Lo instala el **paso 1** de `instalar.sh`: crea al usuario por cada `.pub`, le
instala su `authorized_keys`, lo mete en el grupo `sudo` (avisa si no tiene
contraseña: `sudo passwd <usuario>`) y, solo cuando ya existe alguna clave y quien
ejecuta el script también tiene la suya, aplica el
endurecimiento y recarga sshd (validando antes con `sshd -t`: un fichero roto
no puede dejarnos fuera del servidor).

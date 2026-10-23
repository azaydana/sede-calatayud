# sistema/ · ASO

Usuarios del servidor y acceso por SSH.

- `usuarios/*.pub`: una por persona; el nombre del fichero es el del usuario que
  se crea en el servidor. `ayoub.pub` tiene su clave. `younes.pub` y `nico.pub`
  están **vacíos**: el usuario se crea (grupo `sudo`), pero no entra por SSH
  hasta que se pegue su clave pública (`~/.ssh/id_ed25519.pub` de su ordenador)
  y se repita `sudo ./instalar.sh`. Pendiente también la del profesorado.
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

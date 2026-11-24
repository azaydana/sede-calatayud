# Pruebas IAW RA5 · Panel BIFI de Calatayud

Fecha: 23 de noviembre de 2026.
URL: http://panel.calatayud.empresa.lan/

## Resultados observados

| Comprobación | Resultado observado |
|---|---|
| A1 · Entrada sin sesión | HTTP 302 a `login.php`. El formulario devuelve HTTP 200. |
| Menú por perfil | Solo CESAR ve el enlace de alta, verificado en PHP. |
| A4 · Ana abre FIS-0002 | HTTP 404. |
| A5 y A8 · Ana y Marta intentan abrir el alta | HTTP 403 para ambas. |
| Validación del alta en PHP | La entrada inválida devolvió cuatro errores y la válida, cero. |
| Alta válida por HTTP | CESAR creó TST-5035. HTTP 302 al guardar y HTTP 200 al abrirlo. |
| A8b · Gestión de miembros en BIO-0001 | Marta añadió y quitó a Luis. Ambas operaciones devolvieron HTTP 302. |
| A10 · Reutilizar la cookie tras cerrar sesión | HTTP 302 a `login.php`. |
| Despliegue persistente | Apache cargó el VirtualHost. La raíz del panel devolvió HTTP 302, el login HTTP 200 y la web principal HTTP 200. |
| DNS autoritativo | BIND devolvió NOERROR y resolvió el nombre del panel a 192.168.6.118 a través de `srv`. |

Las pruebas de perfiles y formularios se hicieron en instancias locales de prueba.
El despliegue persistente se comprobó desde el servidor con `curl --resolve`.

## Pendiente de verificar

- Acceso por nombre desde un equipo del aula usando su DNS habitual.
- Consumo visible de Ana y Marta, incluida la estimación de Marta.
- Alta inválida por HTTP, con cuatro mensajes en el formulario y sin guardar el proyecto.
- Resto de casos del enunciado que todavía no tienen evidencia registrada.

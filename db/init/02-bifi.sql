-- Reto 1 · La base del BIFI (modelo en reto-1/modelo-datos.md)
--
-- Se ejecuta solo al crear el volumen (como 01-esquema.sql), conectado a la BD
-- de POSTGRES_DB. Crea los roles, la base bifi, sus tablas, las vistas de los
-- responsables (RA3) y datos de ejemplo. Idempotente: instalar.sh lo vuelve a
-- lanzar en cada ejecución, también sobre una base que ya existe:
--   docker compose exec -T db psql -U "$DB_USUARIO" -d postgres < db/init/02-bifi.sql
--
-- Sin contraseñas: salen de .env y las pone 03-claves.sh.
-- Las cuentas del panel tienen como contraseña de ejemplo «bifi2026» (en la
-- tabla usuario solo está su hash de password_hash()).

\set ON_ERROR_STOP on

-- ============================================================ roles
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'responsables') THEN
        CREATE ROLE responsables NOLOGIN;            -- grupo: los permisos van aquí
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'panel') THEN
        CREATE ROLE panel LOGIN CONNECTION LIMIT 10;
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'resp_demo') THEN
        CREATE ROLE resp_demo LOGIN CONNECTION LIMIT 2 IN ROLE responsables;
    END IF;
END
$$;

-- El panel no se queda colgado: sentencias de 5 s como mucho (C6)
ALTER ROLE panel SET statement_timeout = '5s';
ALTER ROLE panel SET idle_in_transaction_session_timeout = '10s';
-- Un responsable con psql puede dejarse la sesión abierta: se corta a la hora
ALTER ROLE responsables SET idle_session_timeout = '1h';
ALTER ROLE resp_demo SET statement_timeout = '30s';

-- ============================================================ base
-- UTF-8, colación española (ICU es-ES) y hora de Madrid (C7).
-- CREATE DATABASE no admite IF NOT EXISTS: se genera solo si falta.
SELECT 'CREATE DATABASE bifi TEMPLATE template0 ENCODING ''UTF8'''
       ' LOCALE_PROVIDER icu ICU_LOCALE ''es-ES'' LOCALE ''C.UTF-8'''
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'bifi')
\gexec

ALTER DATABASE bifi SET timezone = 'Europe/Madrid';
REVOKE CONNECT, TEMPORARY ON DATABASE bifi FROM PUBLIC;
GRANT CONNECT ON DATABASE bifi TO panel, responsables;

\connect bifi

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- ============================================================ tablas
CREATE TABLE IF NOT EXISTS usuario (
    usuario  varchar(30)  PRIMARY KEY,
    nombre   varchar(100) NOT NULL,
    perfil   varchar(12)  NOT NULL
             CHECK (perfil IN ('investigador', 'responsable', 'cesar')),
    hash     varchar(255) NOT NULL                -- password_hash() de PHP
);

CREATE TABLE IF NOT EXISTS tarifa (
    tipo                varchar(20)  PRIMARY KEY,
    precio_nucleo_hora  numeric(6,3) NOT NULL CHECK (precio_nucleo_hora >= 0)
);

CREATE TABLE IF NOT EXISTS proyecto (
    codigo       char(8)      PRIMARY KEY
                 CHECK (codigo ~ '^[A-Z]{3}-[0-9]{4}$'),   -- BIO-0001
    titulo       varchar(200) NOT NULL,
    responsable  varchar(30)  NOT NULL REFERENCES usuario,
    tarifa       varchar(20)  NOT NULL REFERENCES tarifa
);

CREATE TABLE IF NOT EXISTS participa (
    proyecto  char(8)     REFERENCES proyecto ON DELETE CASCADE,
    usuario   varchar(30) REFERENCES usuario,
    PRIMARY KEY (proyecto, usuario)
);

CREATE TABLE IF NOT EXISTS maquina (
    nombre  varchar(20) PRIMARY KEY
);

-- Sin clave ajena a participa: si el responsable saca a alguien del proyecto,
-- lo que ya gastó se sigue cobrando. Que participe se comprueba al insertar.
CREATE TABLE IF NOT EXISTS uso (
    id        integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    proyecto  char(8)      NOT NULL REFERENCES proyecto,
    usuario   varchar(30)  NOT NULL REFERENCES usuario,
    maquina   varchar(20)  NOT NULL REFERENCES maquina,
    fecha     date         NOT NULL,
    nucleos   integer      NOT NULL CHECK (nucleos > 0),
    horas     numeric(8,2) NOT NULL CHECK (horas > 0)
);

CREATE INDEX IF NOT EXISTS uso_proyecto_idx ON uso (proyecto);
CREATE INDEX IF NOT EXISTS uso_usuario_idx  ON uso (usuario);

-- Recuperar la contraseña desde el panel (comun/recuperacion.php).
-- Del token solo se guarda su sha256: quien lea la tabla no puede usar los enlaces.
CREATE TABLE IF NOT EXISTS solicitud (
    usuario  varchar(30) PRIMARY KEY REFERENCES usuario ON DELETE CASCADE,
    fecha    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS recuperacion (
    hash     char(64)    PRIMARY KEY,
    usuario  varchar(30) NOT NULL REFERENCES usuario ON DELETE CASCADE,
    caduca   timestamptz NOT NULL
);

-- RA3 · Qué usuario del BIFI es cada rol de PostgreSQL que entra con psql.
-- Las vistas de abajo filtran por current_user a través de esta tabla.
CREATE TABLE IF NOT EXISTS cuenta_bd (
    rol      name        PRIMARY KEY,
    usuario  varchar(30) NOT NULL UNIQUE REFERENCES usuario
);

-- ============================================================ reglas entre tablas
-- Un CHECK no puede mirar otra tabla: estas reglas del modelo van en disparadores.

CREATE OR REPLACE FUNCTION exige_perfil(p_usuario text, p_perfil text, p_que text)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT FROM usuario WHERE usuario = p_usuario AND perfil = p_perfil) THEN
        RAISE EXCEPTION '% «%» no tiene perfil %', p_que, p_usuario, p_perfil;
    END IF;
END
$$;

CREATE OR REPLACE FUNCTION proyecto_responsable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM exige_perfil(NEW.responsable, 'responsable', 'El responsable');
    RETURN NEW;
END
$$;

CREATE OR REPLACE FUNCTION participa_investigador() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM exige_perfil(NEW.usuario, 'investigador', 'El miembro');
    RETURN NEW;
END
$$;

CREATE OR REPLACE FUNCTION uso_participa() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT FROM participa
                   WHERE proyecto = NEW.proyecto AND usuario = NEW.usuario) THEN
        RAISE EXCEPTION '«%» no participa en %', NEW.usuario, NEW.proyecto;
    END IF;
    RETURN NEW;
END
$$;

CREATE OR REPLACE TRIGGER proyecto_responsable
    BEFORE INSERT OR UPDATE OF responsable ON proyecto
    FOR EACH ROW EXECUTE FUNCTION proyecto_responsable();

CREATE OR REPLACE TRIGGER participa_investigador
    BEFORE INSERT OR UPDATE ON participa
    FOR EACH ROW EXECUTE FUNCTION participa_investigador();

CREATE OR REPLACE TRIGGER uso_participa
    BEFORE INSERT OR UPDATE OF proyecto, usuario ON uso
    FOR EACH ROW EXECUTE FUNCTION uso_participa();

-- ============================================================ datos
-- Los de panel/datos-ejemplo/ y algunos más, para que haya de todo:
-- un investigador en dos proyectos, un responsable con dos, las dos tarifas
-- y las tres máquinas.

INSERT INTO tarifa (tipo, precio_nucleo_hora) VALUES
    ('academica', 0.010),
    ('empresa',   0.035)
ON CONFLICT DO NOTHING;

INSERT INTO maquina (nombre) VALUES
    ('cierzo'), ('agustina'), ('colossus')
ON CONFLICT DO NOTHING;

INSERT INTO usuario (usuario, nombre, perfil, hash) VALUES
    ('ana',   'Ana Lorente',          'investigador', '$2y$10$46h.P91DIGOFh6zwFu9REeuZvuU1HrV3rGwoc9q0ttAj10p0MO3WO'),
    ('luis',  'Luis Sanz',            'investigador', '$2y$10$B7Szkbq7EED7x14tCw/92eGjTgaoQJKDIw8J4Rl314BYsWVEkdXze'),
    ('sofia', 'Sofía Escartín',       'investigador', '$2y$10$9aj2mrc2YVVEQ3r.pFe6EejZxQQFBq.x986pt6IZ1EQvgoEcLtFG2'),
    ('pablo', 'Pablo Aznar',          'investigador', '$2y$10$0dYN9APwH/sNhblDMQSikO.1vazEKvsy64b6K/8CP6tluy03NNlwe'),
    ('irene', 'Irene Lacasa',         'investigador', '$2y$10$.219Mk6glq273ouKVbv57O1Tz3Xx4OWeitKJWP5nP7/xRhodoxpEi'),
    ('david', 'David Ballarín',       'investigador', '$2y$10$y2b/nNssLqgREhiTOL.3z.BTMnO/JSlz3xMro8M9dEBXdzsGTgGDC'),
    ('marta', 'Marta Gil',            'responsable',  '$2y$10$EcM9YJsNjpxTgd34Bud4XeNh0X6rnYeLvNjvYORMRWIKAMG833w1y'),
    ('jorge', 'Jorge Pina',           'responsable',  '$2y$10$cRgH9QpSi5.jYXePKghjFu0VQSmZ4hk/l6mm4o0yjlAaOJtCjscNm'),
    ('elena', 'Elena Casaús',         'responsable',  '$2y$10$B2.CDSfZIznx8vcXfWw9bOG7AH5Y49nCFgo3GkeOD6lQViUsENigC'),
    ('cesar', 'Administración CESAR', 'cesar',        '$2y$10$o8WiAxl4gEthdaWtldcO7uJxq0ZSWJ1Wt1PZw3v0Qp/FAUH.cco6W')
ON CONFLICT DO NOTHING;

INSERT INTO proyecto (codigo, titulo, responsable, tarifa) VALUES
    ('BIO-0001', 'Plegamiento de proteínas de membrana',      'marta', 'academica'),
    ('FIS-0002', 'Simulación de materiales para baterías',    'jorge', 'empresa'),
    ('QUI-0003', 'Catalizadores para hidrógeno verde',        'marta', 'academica'),
    ('CLI-0004', 'Modelo regional de cierzo y sequía',        'elena', 'academica'),
    ('IND-0005', 'Optimización de rutas logísticas en PLAZA', 'jorge', 'empresa')
ON CONFLICT DO NOTHING;

INSERT INTO participa (proyecto, usuario) VALUES
    ('BIO-0001', 'ana'),   ('BIO-0001', 'sofia'),
    ('FIS-0002', 'luis'),  ('FIS-0002', 'pablo'),
    ('QUI-0003', 'irene'), ('QUI-0003', 'ana'),
    ('CLI-0004', 'david'), ('CLI-0004', 'sofia'),
    ('IND-0005', 'irene')
ON CONFLICT DO NOTHING;

-- uso no tiene clave natural: se rellena solo si está vacía.
INSERT INTO uso (proyecto, usuario, maquina, fecha, nucleos, horas)
SELECT v.proyecto, v.usuario, v.maquina, v.fecha::date, v.nucleos, v.horas
FROM (VALUES
    ('BIO-0001', 'ana',   'cierzo',   '2026-11-02', 128,  6.5),
    ('BIO-0001', 'ana',   'agustina', '2026-11-04',  64, 12.0),
    ('FIS-0002', 'luis',  'cierzo',   '2026-11-03', 256,  3.0),
    ('FIS-0002', 'luis',  'colossus', '2026-11-05',  32, 20.0),
    ('BIO-0001', 'sofia', 'cierzo',   '2026-11-06',  96,  8.0),
    ('BIO-0001', 'ana',   'cierzo',   '2026-11-12', 128,  4.5),
    ('BIO-0001', 'sofia', 'colossus', '2026-11-18',  16, 30.0),
    ('FIS-0002', 'pablo', 'agustina', '2026-11-09', 128, 10.0),
    ('FIS-0002', 'luis',  'cierzo',   '2026-11-16', 512,  2.0),
    ('FIS-0002', 'pablo', 'colossus', '2026-11-20',  64, 14.5),
    ('QUI-0003', 'irene', 'agustina', '2026-11-05',  48, 18.0),
    ('QUI-0003', 'ana',   'cierzo',   '2026-11-10', 192,  5.0),
    ('QUI-0003', 'irene', 'colossus', '2026-11-19',  24, 36.0),
    ('CLI-0004', 'david', 'cierzo',   '2026-11-07', 384,  9.0),
    ('CLI-0004', 'david', 'agustina', '2026-11-14', 128, 22.0),
    ('CLI-0004', 'sofia', 'cierzo',   '2026-11-21', 256,  6.0),
    ('IND-0005', 'irene', 'agustina', '2026-11-11',  96,  7.5),
    ('IND-0005', 'irene', 'cierzo',   '2026-11-17', 256,  4.0),
    ('IND-0005', 'irene', 'colossus', '2026-11-23',  32, 16.0),
    ('CLI-0004', 'david', 'colossus', '2026-11-24',  64, 11.0)
) AS v (proyecto, usuario, maquina, fecha, nucleos, horas)
WHERE NOT EXISTS (SELECT FROM uso);

-- resp_demo es la cuenta de psql de Marta (BIO-0001 y QUI-0003)
INSERT INTO cuenta_bd (rol, usuario) VALUES ('resp_demo', 'marta')
ON CONFLICT DO NOTHING;

-- ============================================================ vistas de los responsables (RA3)
-- Un responsable ve sus proyectos, quién está en ellos, el consumo y la
-- factura, y nada más: ni hash, ni proyectos de otros. security_barrier
-- impide que una función suya en el WHERE vea filas antes del filtro.

CREATE OR REPLACE VIEW mis_proyectos WITH (security_barrier) AS
SELECT p.codigo, p.titulo, p.tarifa, t.precio_nucleo_hora
FROM proyecto p
JOIN tarifa t ON t.tipo = p.tarifa
WHERE p.responsable = (SELECT usuario FROM cuenta_bd WHERE rol = current_user);

CREATE OR REPLACE VIEW mis_miembros WITH (security_barrier) AS
SELECT pa.proyecto, u.usuario, u.nombre
FROM participa pa
JOIN usuario u ON u.usuario = pa.usuario
WHERE pa.proyecto IN (SELECT codigo FROM mis_proyectos);

CREATE OR REPLACE VIEW mi_consumo WITH (security_barrier) AS
SELECT us.proyecto, us.usuario, us.maquina, us.fecha, us.nucleos, us.horas,
       us.nucleos * us.horas AS nucleo_horas
FROM uso us
WHERE us.proyecto IN (SELECT codigo FROM mis_proyectos);

CREATE OR REPLACE VIEW mi_factura WITH (security_barrier) AS
SELECT p.codigo, p.titulo, p.tarifa,
       count(c.*)                                                  AS trabajos,
       coalesce(sum(c.nucleo_horas), 0)                             AS nucleo_horas,
       round(coalesce(sum(c.nucleo_horas), 0) * p.precio_nucleo_hora, 2) AS euros
FROM mis_proyectos p
LEFT JOIN mi_consumo c ON c.proyecto = p.codigo
GROUP BY p.codigo, p.titulo, p.tarifa, p.precio_nucleo_hora;

-- ============================================================ permisos
-- Nadie toca las tablas salvo lo que se le da aquí (privilegio mínimo).
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO panel, responsables;

-- Panel: lo justo para lo que hace (modelo-datos.md y recuperación de clave)
GRANT SELECT ON usuario, tarifa, proyecto, participa, maquina, uso,
                solicitud, recuperacion TO panel;
GRANT UPDATE (hash) ON usuario TO panel;           -- cambiar la contraseña
GRANT INSERT ON proyecto TO panel;                  -- alta (CESAR)
GRANT INSERT, DELETE ON participa TO panel;         -- miembros (responsable)
GRANT INSERT, DELETE ON solicitud, recuperacion TO panel;

-- Responsables: solo las vistas, nunca las tablas
GRANT SELECT ON mis_proyectos, mis_miembros, mi_consumo, mi_factura TO responsables;

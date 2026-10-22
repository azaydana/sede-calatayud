-- Sede Calatayud — esquema inicial (W4: >=20 filas).
-- La BD se crea vía POSTGRES_DB; este script crea la tabla y los datos.

CREATE TABLE IF NOT EXISTS clientes (
    id SERIAL PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    telefono VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW()
);

INSERT INTO clientes (nombre, email, telefono) VALUES
('Ana Beltrán', 'ana.beltran@example.lan', '976100001'),
('Jorge Lázaro', 'jorge.lazaro@example.lan', '976100002'),
('María Gil', 'maria.gil@example.lan', '976100003'),
('Pablo Royo', 'pablo.royo@example.lan', '976100004'),
('Lucía Marco', 'lucia.marco@example.lan', '976100005'),
('David Sanz', 'david.sanz@example.lan', '976100006'),
('Elena Vicente', 'elena.vicente@example.lan', '976100007'),
('Raúl Moros', 'raul.moros@example.lan', '976100008'),
('Sara Bielsa', 'sara.bielsa@example.lan', '976100009'),
('Iker Gracia', 'iker.gracia@example.lan', '976100010'),
('Nerea Langa', 'nerea.langa@example.lan', '976100011'),
('Óscar Tobajas', 'oscar.tobajas@example.lan', '976100012'),
('Irene Cebrián', 'irene.cebrian@example.lan', '976100013'),
('Adrián Muñoz', 'adrian.munoz@example.lan', '976100014'),
('Carmen Pérez', 'carmen.perez@example.lan', '976100015'),
('Javier Artal', 'javier.artal@example.lan', '976100016'),
('Marta Soler', 'marta.soler@example.lan', '976100017'),
('Sergio Yus', 'sergio.yus@example.lan', '976100018'),
('Laura Hernando', 'laura.hernando@example.lan', '976100019'),
('Diego Catalán', 'diego.catalan@example.lan', '976100020'),
('Paula Remón', 'paula.remon@example.lan', '976100021'),
('Hugo Lafuente', 'hugo.lafuente@example.lan', '976100022'),
('Ainhoa Gálvez', 'ainhoa.galvez@example.lan', '976100023'),
('Mario Cacho', 'mario.cacho@example.lan', '976100024'),
('Clara Morata', 'clara.morata@example.lan', '976100025')
ON CONFLICT (email) DO NOTHING;

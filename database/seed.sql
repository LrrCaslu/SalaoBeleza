-- Fictitious test content. Safe to run once after schema.sql.
INSERT INTO settings (id,salon_name,subtitle,hero_title,hero_description,about_text,address,city,phone,whatsapp,email,instagram,timezone,booking_lead_minutes,booking_horizon_days,cancellation_hours)
VALUES (1,'Essenza Beauty Studio','Beleza com essência','Sua beleza merece um momento especial.','Cuidado personalizado, talento e uma pausa só sua. Descubra a experiência Essenza.','Acreditamos que o verdadeiro luxo está nos detalhes. Nossa equipe combina técnica, acolhimento e produtos selecionados para valorizar a beleza singular de cada pessoa.','Rua Exemplo, 123 — Centro','Sua cidade / SC','(48) 99999-9999','5548999999999','contato@essenza.exemplo','https://www.instagram.com/','America/Sao_Paulo',60,90,12)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO categories (id,name,icon,sort_order) VALUES
(1,'Cabelos','scissors',1),(2,'Manicure e pedicure','hand',2),(3,'Sobrancelhas e cílios','eye',3),
(4,'Maquiagem','brush',4),(5,'Estética facial','sparkles',5),(6,'Tratamentos especiais','flower-2',6)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO services (id,category_id,name,description,price,duration_minutes,image) VALUES
(1,1,'Corte e finalização','Corte personalizado com lavagem e finalização.',120,60,'hair.webp'),
(2,1,'Coloração premium','Cor, brilho e tratamento com diagnóstico capilar.',280,120,'hair.webp'),
(3,1,'Hidratação profunda','Tratamento nutritivo e reparação dos fios.',150,60,'hair.webp'),
(4,2,'Manicure em gel','Unhas impecáveis, durabilidade e acabamento delicado.',130,60,'nails.webp'),
(5,2,'Manicure tradicional','Cutilagem cuidadosa e esmaltação.',65,45,'nails.webp'),
(6,2,'Pedicure spa','Esfoliação, hidratação e cuidado dos pés.',95,60,'nails.webp'),
(7,3,'Design de sobrancelhas','Mapeamento facial e design harmonioso.',80,30,'brows.webp'),
(8,3,'Lash lifting','Curvatura natural e valorização dos cílios.',160,75,'brows.webp'),
(9,4,'Maquiagem social','Produção sofisticada para ocasiões especiais.',220,90,'look.webp'),
(10,5,'Limpeza de pele','Higienização e cuidado facial personalizado.',190,75,'facial.webp'),
(11,5,'Revitalização facial','Protocolo de renovação, luminosidade e conforto.',210,60,'facial.webp'),
(12,6,'Massagem relaxante','Uma pausa para corpo e mente com técnicas suaves.',190,60,'massage.webp')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO staff (id,name,specialty,bio,photo) VALUES
(1,'Lena Morgan','Hair stylist','Especialista em cortes com movimento e beleza natural.','staff-1.webp'),
(2,'Isabella Reyes','Colorista','Técnicas de coloração, luzes e saúde capilar.','staff-2.webp'),
(3,'Maya Kim','Visagismo e estética','Olhar delicado para pele, maquiagem e sobrancelhas.','staff-3.webp'),
(4,'Tessa Rowe','Nail artist e bem-estar','Arte das unhas, rituais e cuidado com cada detalhe.','staff-4.webp')
ON DUPLICATE KEY UPDATE id=id;

INSERT IGNORE INTO staff_services (staff_id,service_id) VALUES
(1,1),(1,2),(1,3),(1,9),
(2,1),(2,2),(2,3),(2,9),
(3,7),(3,8),(3,9),(3,10),(3,11),(3,12),
(4,4),(4,5),(4,6),(4,7),(4,10),(4,11),(4,12);

INSERT INTO salon_hours (weekday,opens,closes,closed) VALUES
(0,NULL,NULL,1),(1,'09:00','19:00',0),(2,'09:00','19:00',0),(3,'09:00','19:00',0),
(4,'09:00','19:00',0),(5,'09:00','19:00',0),(6,'09:00','17:00',0)
ON DUPLICATE KEY UPDATE weekday=weekday;

INSERT IGNORE INTO staff_hours(staff_id,weekday,starts,ends,break_starts,break_ends,enabled)
SELECT s.id, h.weekday, h.opens, h.closes, '12:00', '13:00', 1 FROM staff s JOIN salon_hours h ON h.closed=0;

INSERT INTO gallery (image,caption,sort_order) VALUES
('about.webp','Um espaço feito para acolher',1),('hair.webp','Cabelos com movimento',2),('nails.webp','Detalhes que encantam',3),
('facial.webp','Rituais de autocuidado',4),('look.webp','Beleza para celebrar',5),('massage.webp','Momentos de bem-estar',6);

INSERT INTO testimonials(customer_name,content,stars) VALUES
('Marina A.','Atendimento impecável, ambiente lindo e muito cuidado em cada detalhe.',5),
('Camila R.','A equipe é extremamente atenciosa. Saí me sentindo renovada!',5),
('Fernanda S.','Experiência maravilhosa, do agendamento ao resultado final.',5);

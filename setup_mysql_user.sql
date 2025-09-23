-- Создание базы данных и пользователя для системы пополнения
CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Удаляем пользователя если существует
DROP USER IF EXISTS 'replenishment_user'@'localhost';

-- Создаем пользователя
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY 'K9#mP2$vQx!8LbN&wZr4FjD7sHq';

-- Даем права
GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';

-- Применяем изменения
FLUSH PRIVILEGES;

-- Показываем результат
SELECT 'База данных и пользователь созданы успешно!' as status;
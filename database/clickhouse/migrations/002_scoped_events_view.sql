-- Kiracı kapsamlı okuma görünümü: salt-okunur kullanıcı (posthog_ro) `events` yerine YALNIZCA bunu okur.
--
-- Neden görünüm: `mergeTreeIndex(...)` gibi tablo fonksiyonları `events` üzerindeki satır politikasını
-- atlar ve diğer projelerin birincil anahtar değerlerini sızdırır; kullanıcı `events` üzerinde SELECT
-- yetkisine sahip olduğu sürece sunucu tarafında kapatılamaz. Kullanıcıda `events` yetkisi hiç yoksa
-- (yalnızca bu görünümde varsa) mergeTreeIndex / merge / view ACCESS_DENIED olur, görünümü de
-- mergeTreeIndex okuyamaz (MergeTree değil).
--
-- DEFINER = CURRENT_USER SQL SECURITY DEFINER: görünümün içindeki `events` okuması bu migration'ı çalıştıran
-- yönetici kullanıcının yetkisiyle yapılır (RO kullanıcının yetkisiyle değil). Sunucu yeniden başlayınca da
-- kalıcıdır (metadata'da saklanır). Yönetici kullanıcı ileride değişirse görünüm `CREATE OR REPLACE VIEW`
-- ile yeniden tanımlanmalı (yeni bir migration).
--
-- Filtre: `project_id = toUInt32(getSetting('SQL_project_id'))`. Ayar yoksa sorgu patlar (Code 115, fail-closed).
-- `getSetting` sabite katlanır, birincil indeks budaması sürer (project_id sıralama anahtarının başında).
-- `SQL_project_id` özel ayarı config.d/10-custom-settings.xml ile tanımlıdır ve görünüm OLUŞTURULURKEN de
-- bilinmelidir: migrator her ifadeyi `SQL_project_id=0` URL argümanıyla çalıştırır (bkz. ClickHouseMigrator).
--
-- AÇIK SÜTUN LİSTESİ (`SELECT *` değil): `*` MATERIALIZED sütunları (current_url, browser, country_code)
-- içermez. Liste ProjectScopedReader::COLUMNS ve `events` ile birebir aynı olmalı. `events`'e sütun
-- ekleyen/silen/yeniden adlandıran bir migration bu görünümü de `CREATE OR REPLACE VIEW` ile yenilemelidir:
-- ADD COLUMN görünümü SESSİZCE eski bırakır (yeni sütun görünmez), DROP/RENAME ise görünümü okunurken
-- patlatır. tests/Feature/ClickHouseTenantIsolationTest.php içindeki sapma testi üçünü (events, görünüm,
-- COLUMNS) karşılaştırır.
CREATE VIEW IF NOT EXISTS project_events_v
DEFINER = CURRENT_USER SQL SECURITY DEFINER
AS SELECT
    uuid, project_id, event, distinct_id, properties, timestamp, created_at, current_url, browser, country_code
FROM events
WHERE project_id = toUInt32(getSetting('SQL_project_id'));

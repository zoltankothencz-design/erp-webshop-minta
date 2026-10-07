CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Termékek. Az ERP a törzsadat ura: név, ár, készlet onnan jön (erp_id az ERP-beli azonosító).
CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  erp_id VARCHAR(40) NOT NULL UNIQUE,
  sku VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(220) NOT NULL UNIQUE,
  category_id INT UNSIGNED NULL,
  brand VARCHAR(100) NULL,
  ean VARCHAR(20) NULL,
  price INT UNSIGNED NOT NULL,                 -- bruttó Ft
  erp_stock INT NOT NULL DEFAULT 0,            -- az ERP szerinti készlet
  stock INT NOT NULL DEFAULT 0,                -- eladható készlet = ERP készlet - még ERP-be nem ment webes rendelések
  active TINYINT(1) NOT NULL DEFAULT 1,
  description TEXT NULL,                       -- az ERP-ből jövő rövid leírás
  description_ai TEXT NULL,                    -- AI-generált leírás (csak jóváhagyás után látszik)
  ai_status ENUM('draft','approved','rejected') NULL,
  ai_generated_at DATETIME NULL,
  image_url VARCHAR(255) NULL,
  erp_updated_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_cat (category_id),
  KEY idx_products_active (active),
  CONSTRAINT fk_products_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  source ENUM('web','marketplace') NOT NULL,
  marketplace_ref VARCHAR(60) NULL,
  customer_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  address VARCHAR(255) NOT NULL,
  total INT UNSIGNED NOT NULL,
  status ENUM('new','sent_to_erp','shipped','cancelled') NOT NULL DEFAULT 'new',
  erp_order_id VARCHAR(40) NULL,
  utm_source VARCHAR(100) NULL,
  utm_medium VARCHAR(100) NULL,
  utm_campaign VARCHAR(100) NULL,
  gclid VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_marketplace (source, marketplace_ref),   -- ugyanazt a marketplace rendelést nem importáljuk kétszer
  KEY idx_orders_status (status),
  KEY idx_orders_created (created_at),
  KEY idx_orders_gclid (gclid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  sku VARCHAR(60) NOT NULL,
  name VARCHAR(200) NOT NULL,
  unit_price INT UNSIGNED NOT NULL,            -- a rendeléskori ár (a termék ára később változhat)
  qty INT UNSIGNED NOT NULL,
  KEY idx_items_order (order_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  channel VARCHAR(40) NOT NULL DEFAULT 'google_ads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_stats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  stat_date DATE NOT NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  cost INT UNSIGNED NOT NULL DEFAULT 0,        -- Ft
  conversions DECIMAL(10,2) NOT NULL DEFAULT 0,
  conv_value INT UNSIGNED NOT NULL DEFAULT 0,  -- a Google szerinti konverziós érték, Ft
  UNIQUE KEY uq_stats (campaign_id, stat_date),
  CONSTRAINT fk_stats_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Melyik rendelést töltöttük már fel offline konverzióként a Google Adsnek
CREATE TABLE conversion_uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL UNIQUE,
  gclid VARCHAR(200) NOT NULL,
  value INT UNSIGNED NOT NULL,
  status ENUM('uploaded','failed') NOT NULL,
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_conv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minden integrációs futás naplója: mi történt, mikor, hány elem
CREATE TABLE sync_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(60) NOT NULL,
  status ENUM('running','ok','error') NOT NULL,
  message VARCHAR(500) NULL,
  items INT NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  KEY idx_sync_job (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kulcs-érték állapot (pl. mikor szinkronizáltunk utoljára)
CREATE TABLE sync_state (
  k VARCHAR(60) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

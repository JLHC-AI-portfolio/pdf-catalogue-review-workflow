CREATE TABLE IF NOT EXISTS import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_file TEXT NOT NULL,
    source_kind TEXT NOT NULL,
    layout_id TEXT NOT NULL,
    draft_item_count INTEGER NOT NULL,
    warning_count INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS draft_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_run_id INTEGER NOT NULL,
    row_number INTEGER NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    size_or_spec TEXT NOT NULL,
    image_ref TEXT,
    attributes_json TEXT NOT NULL,
    confidence REAL NOT NULL,
    review_status TEXT NOT NULL,
    source_page INTEGER NOT NULL,
    source_excerpt TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (import_run_id) REFERENCES import_runs(id)
);

CREATE TABLE IF NOT EXISTS image_references (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draft_product_id INTEGER NOT NULL,
    image_ref TEXT NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (draft_product_id) REFERENCES draft_products(id)
);

CREATE TABLE IF NOT EXISTS import_warnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_run_id INTEGER NOT NULL,
    draft_product_id INTEGER,
    code TEXT NOT NULL,
    severity TEXT NOT NULL,
    message TEXT NOT NULL,
    source_field TEXT,
    source_excerpt TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (import_run_id) REFERENCES import_runs(id),
    FOREIGN KEY (draft_product_id) REFERENCES draft_products(id)
);

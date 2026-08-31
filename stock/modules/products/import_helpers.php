<?php
// stock/modules/products/import_helpers.php
if (!function_exists('stock_import_norm_key')) {
    function stock_import_norm_key($s) {
        $s = strtolower(trim((string)$s));
        $s = preg_replace('/\s+/', '_', $s);
        return preg_replace('/[^a-z0-9_]/', '', $s);
    }
}
if (!function_exists('stock_import_get_any')) {
    function stock_import_get_any(array $row, array $keys, $default = null) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return $row[$k];
        }
        return $default;
    }
}
if (!function_exists('stock_import_to_num')) {
    function stock_import_to_num($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace([',', ' '], '', $v);
        return is_numeric($v) ? (float)$v : null;
    }
}
if (!function_exists('stock_import_to_int')) {
    function stock_import_to_int($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace([',', ' '], '', $v);
        return is_numeric($v) ? (int)$v : null;
    }
}
if (!function_exists('stock_import_norm_mode')) {
    function stock_import_norm_mode($v) {
        $v = strtolower(trim((string)$v));
        if ($v === 'truck' || $v === 'vehicle') return 'truck';
        if ($v === 'general') return 'general';
        return 'spare_part';
    }
}
if (!function_exists('stock_import_is_ultimate')) {
    function stock_import_is_ultimate() {
        return (isset($_SERVER['REQUEST_URI']) && strpos((string)$_SERVER['REQUEST_URI'], '/ultimate/') !== false)
            || (!empty($_SESSION['company_slug']) && strtolower((string)$_SESSION['company_slug']) === 'ultimate');
    }
}
if (!function_exists('stock_import_has_col')) {
    function stock_import_has_col(PDO $pdo, $table, $col) {
        static $cache = [];
        $key = $table . '.' . $col;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`','',$table) . '` LIKE ' . $pdo->quote($col));
            $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $cache[$key] = false; }
        return $cache[$key];
    }
}
if (!function_exists('stock_import_read_csv')) {
    function stock_import_read_csv($tmpPath, array &$errors) {
        $rows = []; $fh = @fopen($tmpPath, 'r');
        if (!$fh) { $errors[] = 'Unable to read uploaded file.'; return []; }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); $errors[] = 'File looks empty.'; return []; }
        $map = []; foreach ($header as $i => $h) $map[$i] = stock_import_norm_key($h);
        while (($line = fgetcsv($fh)) !== false) {
            if (count(array_filter($line, function ($x) { return trim((string)$x) !== ''; })) === 0) continue;
            $row = []; foreach ($line as $i => $v) { $row[$map[$i] ?? ('col_'.$i)] = $v; }
            $rows[] = $row;
        }
        fclose($fh); return $rows;
    }
}
if (!function_exists('stock_import_read_html_xls')) {
    function stock_import_read_html_xls($tmpPath, array &$errors) {
        $rows = []; $html = @file_get_contents($tmpPath);
        if ($html === false || trim($html) === '') { $errors[] = 'Unable to read uploaded XLS file.'; return []; }
        $offset = 0; $header = null; $map = [];
        while (preg_match('~<tr\b[^>]*>(.*?)</tr>~is', $html, $m, 0, $offset)) {
            $rowHtml = $m[1]; $offset += strlen($m[0]);
            if (!preg_match_all('~<(td|th)\b[^>]*>(.*?)</\1>~is', $rowHtml, $cm)) continue;
            $cells = [];
            foreach ($cm[2] as $cellHtml) {
                $cells[] = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($cellHtml))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $hasAny = count(array_filter($cells, function ($x) { return trim((string)$x) !== ''; })) > 0;
            if ($header === null) {
                if (!$hasAny) continue;
                $header = $cells; foreach ($header as $i => $h) $map[$i] = stock_import_norm_key($h); continue;
            }
            if (!$hasAny) continue;
            $row = []; foreach ($cells as $i => $v) { $row[$map[$i] ?? ('col_'.$i)] = $v; }
            $rows[] = $row;
        }
        if ($header === null) { $errors[] = 'Unable to detect header row in XLS.'; return []; }
        return $rows;
    }
}
if (!function_exists('stock_import_read_upload')) {
    function stock_import_read_upload(array $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok'=>false,'message'=>'Upload failed. Please try again.'];
        $name = (string)($file['name'] ?? 'import.csv');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return ['ok'=>false,'message'=>'Upload failed. Please try again.'];
        if ((int)($file['size'] ?? 0) > 10*1024*1024) return ['ok'=>false,'message'=>'That file is larger than 10MB. Split it and try again.'];
        $errors = [];
        if (in_array($ext, ['csv','txt'], true)) $rows = stock_import_read_csv($tmp, $errors);
        elseif (in_array($ext, ['xls','html','htm'], true)) $rows = stock_import_read_html_xls($tmp, $errors);
        elseif ($ext === 'xlsx') return ['ok'=>false,'message'=>'Please save as .xls or .csv (.xlsx is not supported yet).'];
        else return ['ok'=>false,'message'=>'Unsupported file type. Upload an Excel (.xls) or CSV (.csv) file.'];
        if ($errors) return ['ok'=>false,'message'=>$errors[0]];
        if (!$rows) return ['ok'=>false,'message'=>'Your file is empty. Add at least one data row.'];
        return ['ok'=>true,'rows'=>$rows,'file_name'=>$name];
    }
}
if (!function_exists('stock_import_validate_shape')) {
    function stock_import_validate_shape(array $rows, $mode, array &$errors) {
        if (!$rows) { $errors[] = 'Your file is empty.'; return false; }
        $keys = array_keys((array)$rows[0]);
        $hasCategory = in_array('category', $keys, true);
        $hasName = false;
        foreach (['name','product_name','productname','part_name','partname','truck_name','truckname'] as $k) { if (in_array($k, $keys, true)) { $hasName = true; break; } }
        if (!$hasName || !$hasCategory) { $errors[] = 'The uploaded file does not match the import template. Download the template and try again.'; return false; }
        return true;
    }
}
if (!function_exists('stock_import_validate_row')) {
    function stock_import_validate_row(PDO $pdo, array $r, int $rowNo, string $mode) {
        $name = trim((string)stock_import_get_any($r, ['name','product_name','productname','part_name','partname','truck_name','truckname'], ''));
        $category = trim((string)stock_import_get_any($r, ['category'], ''));
        $code = trim((string)stock_import_get_any($r, ['product_code','productcode'], ''));
        if ($name !== '' && stripos($name, '__DUMMY__') === 0) return ['skip'=>true,'ok'=>false,'issues'=>[],'row_no'=>$rowNo,'product_code'=>$code,'name'=>$name,'category'=>$category,'will_update'=>false,'row_data'=>$r];
        $issues = [];
        if ($name === '') $issues[] = ['field'=>'Name','issue'=>'Missing required name.','fix'=>'Enter a product name.'];
        if ($mode !== 'truck' && $category === '') $issues[] = ['field'=>'Category','issue'=>'Missing required category.','fix'=>'Enter a category name.'];
        foreach (['Buying price'=>['buying_price','buyingprice'],'Selling price'=>['selling_price','sellingprice','unit_price','unitprice']] as $pretty=>$keys) {
            $raw = stock_import_get_any($r, $keys, null);
            if ($raw !== null && trim((string)$raw) !== '' && stock_import_to_num($raw) === null) {
                $issues[] = ['field'=>$pretty,'issue'=>"$pretty must be a valid number.",'fix'=>'Remove currency symbols.'];
            }
        }
        $willUpdate = false;
        if ($code !== '') {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE product_code = ? LIMIT 1');
            $stmt->execute([$code]);
            $willUpdate = (bool)$stmt->fetchColumn();
        }
        return ['ok'=>empty($issues),'skip'=>false,'issues'=>$issues,'row_no'=>$rowNo,'product_code'=>$code,'name'=>$name,'category'=>$category,'will_update'=>$willUpdate,'row_data'=>$r];
    }
}
if (!function_exists('stock_import_ensure_category')) {
    function stock_import_ensure_category(PDO $pdo, $name) {
        $name = trim((string)$name); if ($name === '') return null;
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1'); $stmt->execute([$name]);
        $id = $stmt->fetchColumn(); if ($id) return (int)$id;
        $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        return (int)$pdo->lastInsertId();
    }
}
if (!function_exists('stock_import_ensure_supplier')) {
    function stock_import_ensure_supplier(PDO $pdo, $name) {
        $name = trim((string)$name); if ($name === '') return null;
        try {
            $stmt = $pdo->prepare("SELECT id FROM contacts WHERE name = ? AND contact_type IN ('Supplier','Both') LIMIT 1");
            $stmt->execute([$name]); $id = $stmt->fetchColumn(); if ($id) return (int)$id;
            $pdo->prepare("INSERT INTO contacts (name, contact_type) VALUES (?, 'Supplier')")->execute([$name]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE name = ? LIMIT 1'); $stmt->execute([$name]);
                $id = $stmt->fetchColumn(); if ($id) return (int)$id;
                $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$name]);
                return (int)$pdo->lastInsertId();
            } catch (Throwable $e2) { return null; }
        }
    }
}
if (!function_exists('stock_import_ensure_brand')) {
    function stock_import_ensure_brand(PDO $pdo, $name, $type = 'spare_part') {
        $name = trim((string)$name); if ($name === '') return null;
        try {
            $stmt = $pdo->prepare('SELECT id FROM brands WHERE name = ? LIMIT 1'); $stmt->execute([$name]);
            if ($stmt->fetchColumn()) return $name;
            $pdo->prepare('INSERT INTO brands (name, brand_type) VALUES (?, ?)')->execute([$name, $type]);
            return $name;
        } catch (Throwable $e) { return $name; }
    }
}
if (!function_exists('stock_import_commit_rows')) {
    function stock_import_commit_rows(PDO $pdo, array $rows, string $mode) {
        $mode = stock_import_norm_mode($mode);
        $itemType = $mode === 'truck' ? 'vehicle' : ($mode === 'general' ? 'general' : 'spare_part');
        $imported = 0; $updated = 0; $skipped = 0; $errors = [];
        $has = function ($c) use ($pdo) { return stock_import_has_col($pdo, 'products', $c); };
        foreach ($rows as $idx => $r) {
            $lineNo = $idx + 2;
            $analysis = stock_import_validate_row($pdo, (array)$r, $lineNo, $mode);
            if (!empty($analysis['skip'])) continue;
            if (empty($analysis['ok'])) { $skipped++; continue; }
            $name = (string)$analysis['name'];
            $categoryName = trim((string)stock_import_get_any($r, ['category'], ''));
            if ($mode === 'truck' && $categoryName === '') $categoryName = 'Trucks';
            $productCode = (string)$analysis['product_code'];
            $supplierName = trim((string)stock_import_get_any($r, ['supplier'], ''));
            $reorder = stock_import_to_int(stock_import_get_any($r, ['reorder_level','reorderlevel'], null));
            $qty = stock_import_to_int(stock_import_get_any($r, ['current_stock','currentstock'], null));
            $location = trim((string)stock_import_get_any($r, ['location'], ''));
            $buying = stock_import_to_num(stock_import_get_any($r, ['buying_price','buyingprice'], null));
            $unitPrice = stock_import_to_num(stock_import_get_any($r, ['unit_price','unitprice'], null));
            $selling = stock_import_to_num(stock_import_get_any($r, ['selling_price','sellingprice'], null));
            if ($unitPrice === null && $selling !== null) $unitPrice = $selling;
            $wholesale = stock_import_to_num(stock_import_get_any($r, ['wholesale_price','wholesaleprice'], null));
            $uom = trim((string)stock_import_get_any($r, ['unit_of_measure','unitofmeasure'], ''));
            $brand = trim((string)stock_import_get_any($r, ['brand','make'], ''));
            $compat = trim((string)stock_import_get_any($r, ['compatibility','compactibility_truck_model','truck_model'], ''));
            $oem = trim((string)stock_import_get_any($r, ['oem_number','oempartnumber','oem_part_number'], ''));
            $cond = trim((string)stock_import_get_any($r, ['part_condition','partcondition','condition'], ''));
            $description = trim((string)stock_import_get_any($r, ['description','notes'], ''));
            try {
                $pdo->beginTransaction();
                $categoryId = stock_import_ensure_category($pdo, $categoryName);
                $supplierId = $supplierName !== '' ? stock_import_ensure_supplier($pdo, $supplierName) : null;
                $brandType = $mode === 'truck' ? 'truck' : ($mode === 'general' ? 'general' : 'spare_part');
                $brandName = $brand !== '' ? stock_import_ensure_brand($pdo, $brand, $brandType) : null;
                $cols = ['name','category_id','supplier_id','unit_price','reorder_level'];
                $vals = [$name, $categoryId, $supplierId, (float)($unitPrice ?? 0), (int)($reorder ?? 10)];
                if ($has('description')) { $cols[]='description'; $vals[] = $description !== '' ? $description : null; }
                if ($has('item_type')) { $cols[]='item_type'; $vals[] = $itemType; }
                if ($has('buying_price')) { $cols[]='buying_price'; $vals[] = (float)($buying ?? 0); }
                if ($has('wholesale_price')) { $cols[]='wholesale_price'; $vals[] = $wholesale !== null ? (float)$wholesale : null; }
                if ($has('unit_of_measure')) { $cols[]='unit_of_measure'; $vals[] = $uom !== '' ? $uom : 'pcs'; }
                if ($has('brand')) { $cols[]='brand'; $vals[] = $brandName; }
                if ($has('oem_number')) { $cols[]='oem_number'; $vals[] = $oem !== '' ? $oem : null; }
                if ($has('part_condition')) { $cols[]='part_condition'; $vals[] = $cond !== '' ? $cond : null; }
                if ($has('compatibility') && $mode !== 'truck') { $cols[]='compatibility'; $vals[] = $compat !== '' ? $compat : null; }
                if ($mode === 'truck') {
                    foreach ([['vin','vin'],['engine_number','engine_number'],['chassis_number','chassis_number'],['color','color']] as $pair) {
                        if ($has($pair[0])) { $cols[]=$pair[0]; $vals[] = trim((string)stock_import_get_any($r, [$pair[1]], '')) ?: null; }
                    }
                    if ($has('model_year')) { $cols[]='model_year'; $vals[] = stock_import_to_int(stock_import_get_any($r, ['model_year','modelyear'], null)); }
                    if ($has('mileage')) { $cols[]='mileage'; $vals[] = stock_import_to_num(stock_import_get_any($r, ['mileage'], null)); }
                }
                $existingId = null;
                if ($productCode !== '') {
                    $q = $pdo->prepare('SELECT id FROM products WHERE product_code = ? LIMIT 1');
                    $q->execute([$productCode]);
                    $col = $q->fetchColumn();
                    $existingId = $col ? (int)$col : null;
                }
                if (!$existingId && $categoryId) {
                    $q = $pdo->prepare('SELECT id FROM products WHERE name = ? AND category_id = ? LIMIT 1');
                    $q->execute([$name, $categoryId]);
                    $col = $q->fetchColumn();
                    $existingId = $col ? (int)$col : null;
                }
                if ($existingId) {
                    $sets = []; foreach ($cols as $c) $sets[] = "$c = ?";
                    $pdo->prepare('UPDATE products SET '.implode(', ',$sets).' WHERE id = ?')->execute(array_merge($vals, [$existingId]));
                    $productId = $existingId; $updated++;
                } else {
                    if ($productCode === '') {
                        $year = date('Y'); $prefix = "PRD-$year-";
                        $stmtMax = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?");
                        $stmtMax->execute([$prefix.'%']);
                        $productCode = $prefix . str_pad((string)(((int)($stmtMax->fetchColumn() ?: 0))+1), 3, '0', STR_PAD_LEFT);
                    }
                    array_unshift($cols, 'product_code'); array_unshift($vals, $productCode);
                    $pdo->prepare('INSERT INTO products ('.implode(',',$cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')')->execute($vals);
                    $productId = (int)$pdo->lastInsertId(); $imported++;
                }
                $qty = $qty !== null ? max(0,(int)$qty) : ($mode === 'truck' ? 1 : 0);
                $location = $location !== '' ? $location : 'Warehouse';
                try {
                    $pdo->prepare('INSERT INTO stock (product_id, quantity, location) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), location = VALUES(location)')->execute([$productId, $qty, $location]);
                } catch (Throwable $e) {}
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "Row $lineNo failed: ".$e->getMessage(); $skipped++;
            }
        }
        return compact('imported','updated','skipped','errors');
    }
}

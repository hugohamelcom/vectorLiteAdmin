<?php
/**
 * VectorLiteAdmin - Vector Database Management Tool
 * 
 * A comprehensive web-based vector database management platform inspired by phpMyAdmin
 * but specifically designed for vector databases using SQLite with embedding support.
 * 
 * @version 1.0.0
 * @author VectorLiteAdmin Team (Hugo Hamel: @hugohamelcom + AI)
 * @license GPL v3
 */

// Configuration - modify these settings as needed
 
// Password configuration - choose one of the following methods:
// Method 1: Set password directly (not recommended for production)
$password = 'admin';

// Method 2: Use MD5 hash (more secure)
// $password = 'md5:21232f297a57a5a743894a0e4a801fc3'; // MD5 of 'admin'

// Method 3: Use SHA256 hash (recommended)
// $password = 'sha256:8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918'; // SHA256 of 'admin'

// Method 4: Use environment variable
// $password = $_ENV['VECTORLITEADMIN_PASSWORD'] ?? 'admin';

// Method 5: Load from external config file (see vectorliteadmin.config.php)

$directory = './databases';
$subdirectories = true;
$theme = 'default';
$max_upload_size = 50; // 50MB

$chunk_size = 1000; // Default chunk size in characters
$chunk_overlap = 200; // Overlap between chunks

// Embedding providers configuration
$embedding_providers = [
    'openai' => [
        'name' => 'OpenAI',
        'api_key' => '', // Set your OpenAI API key
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
        'endpoint' => 'https://api.openai.com/v1/embeddings'
    ],
    'gemini' => [
        'name' => 'Gemini',
        'api_key' => '', // Google AI API key
        'model' => 'gemini-embedding-001',
        'dimensions' => 3072,
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent'
    ],
    'ollama' => [
        'name' => 'Ollama',
        'endpoint' => 'http://localhost:11434/api/embeddings',
        'model' => 'nomic-embed-text',
        'dimensions' => 768
    ],
    'lmstudio' => [
        'name' => 'LM Studio',
        'endpoint' => 'http://localhost:1234/v1/embeddings',
        'api_key' => '',
        'model' => 'text-embedding-model',
        'dimensions' => 1536
    ]
];

$default_provider = 'gemini';

// Security settings
$cookie_name = 'vectorliteadmin_auth';
$session_timeout = 3600; // 1 hour

if (file_exists(__DIR__ . '/vectorliteadmin.config.php')) {
    include __DIR__ . '/vectorliteadmin.config.php';
}

/**
 * Count documents for pagination
 */
function countDocuments($group_filter = null) {
    global $pdo;
    $sql = "SELECT COUNT(DISTINCT d.id) AS cnt FROM documents d";
    $params = [];
    if ($group_filter) {
        // Join with document_groups to filter by group
        $sql .= " JOIN document_groups dg ON d.id = dg.document_id
                  JOIN content_groups g ON dg.group_id = g.id
                  WHERE g.name = ?";
        $params[] = $group_filter;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['cnt'] ?? 0);
}

// Global variables
$databases = [];
$current_db = null;
$pdo = null;

// Start session and handle authentication
session_start();

/**
 * Scan for databases in the configured directory
 */
function scanDatabases() {
    global $directory, $subdirectories, $databases;
    
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    
    $databases = findDatabases($directory, $subdirectories);
}

/**
 * Recursively find SQLite databases
 */
function findDatabases($dir, $recursive = false) {
    $databases = [];
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($path) && $recursive) {
            $databases = array_merge($databases, findDatabases($path, true));
        } elseif (is_file($path) && isSQLiteDatabase($path)) {
            $real_path = realpath($path) ?: $path;
            $databases[] = [
                'name' => basename($file, '.sqlite'),
                'path' => $real_path,
                'size' => filesize($path),
                'modified' => filemtime($path),
                'is_vector_db' => isVectorDatabase($path)
            ];
        }
    }
    
    return $databases;
}

/**
 * Check if file is a SQLite database
 */
function isSQLiteDatabase($path) {
    if (!file_exists($path)) return false;
    
    $handle = fopen($path, 'rb');
    if (!$handle) return false;
    
    $header = fread($handle, 16);
    fclose($handle);
    
    return strpos($header, 'SQLite format 3') === 0;
}

/**
 * Check if database has vector schema
 */
function isVectorDatabase($path) {
    try {
        $pdo = new PDO("sqlite:$path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $tables = ['documents', 'chunks', 'embeddings'];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$table]);
            if (!$stmt->fetch()) {
                return false;
            }
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Connect to a database
 */
function connectDatabase($path) {
    global $pdo, $current_db;

    try {
        $pdo = new PDO("sqlite:$path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $current_db = $path;

        // Initialize vector schema if needed
        if (!isVectorDatabase($path)) {
            initializeVectorSchema();
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Initialize vector database schema
 */
function initializeVectorSchema() {
    global $pdo;
    
    $schema = "
    CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        metadata TEXT,
        document_key TEXT UNIQUE,
        group_name TEXT DEFAULT 'default',
        file_type TEXT,
        file_size INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE IF NOT EXISTS chunks (
        id INTEGER PRIMARY KEY,
        document_id INTEGER,
        chunk_index INTEGER,
        content TEXT NOT NULL,
        metadata TEXT,
        token_count INTEGER,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
    );
    
    CREATE TABLE IF NOT EXISTS embeddings (
        id INTEGER PRIMARY KEY,
        chunk_id INTEGER,
        embedding BLOB,
        model TEXT,
        dimensions INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
    );
    
    CREATE TABLE IF NOT EXISTS embedding_queue (
        id INTEGER PRIMARY KEY,
        chunk_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        status TEXT DEFAULT 'pending',
        attempts INTEGER DEFAULT 0,
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
    );
    
    CREATE TABLE IF NOT EXISTS content_groups (
        id INTEGER PRIMARY KEY,
        name TEXT UNIQUE NOT NULL,
        description TEXT,
        color TEXT DEFAULT '#007cba',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS document_groups (
        id INTEGER PRIMARY KEY,
        document_id INTEGER NOT NULL,
        group_id INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
        FOREIGN KEY (group_id) REFERENCES content_groups(id) ON DELETE CASCADE,
        UNIQUE(document_id, group_id)
    );

    CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type TEXT DEFAULT 'string',
        description TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE VIRTUAL TABLE IF NOT EXISTS chunk_index USING fts5(
        content,
        content_rowid='id',
        content='chunks'
    );
    
    INSERT OR IGNORE INTO content_groups (name, description) VALUES ('default', 'Default content group');
    ";
    
    $pdo->exec($schema);
}

/**
 * Get database statistics
 */
function getDatabaseStats() {
    global $pdo;
    
    if (!$pdo) return null;
    
    $stats = [];
    
    // Document count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents");
    $stats['documents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Chunk count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM chunks");
    $stats['chunks'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Embedding count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM embeddings");
    $stats['embeddings'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Pending embeddings
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM embedding_queue WHERE status = 'pending'");
    $stats['pending_embeddings'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Groups
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM content_groups");
    $stats['groups'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    return $stats;
}

/**
 * Upload and process files
 */
function uploadFiles($files, $groups = ['default'], $duplicates = null) {
    global $pdo;

    // Ensure groups is an array
    if (!is_array($groups)) {
        $groups = [$groups];
    }

    // Ensure at least one group is selected
    if (empty($groups)) {
        $groups = ['default'];
    }

    $results = [];

    foreach ($files['tmp_name'] as $key => $tmp_name) {
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            $results[] = ['success' => false, 'filename' => $files['name'][$key], 'error' => 'Upload failed'];
            continue;
        }

        $filename = $files['name'][$key];
        $file_type = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $file_size = $files['size'][$key];

        // Extract content based on file type
        $content = extractContent($tmp_name, $file_type);
        if (!$content) {
            $results[] = ['success' => false, 'filename' => $filename, 'error' => 'Could not extract content'];
            continue;
        }

        // Get the first group name for backward compatibility (documents table still has group_name column)
        $first_group_name = 'default';
        if (!empty($groups) && is_numeric($groups[0])) {
            // If groups are IDs, get the group name
            $stmt = $pdo->prepare("SELECT name FROM content_groups WHERE id = ?");
            $stmt->execute([$groups[0]]);
            $group_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($group_row) {
                $first_group_name = $group_row['name'];
            }
        } elseif (!empty($groups) && is_string($groups[0])) {
            $first_group_name = $groups[0];
        }

        // Check if this is a duplicate file being replaced
        $existing_document_id = null;
        $existing_groups = [];
        $is_replacement = false;

        if ($duplicates !== null && !empty($duplicates)) {
            // Find if this file is in the duplicates list
            $duplicate_info = null;
            foreach ($duplicates as $dup) {
                if ($dup['filename'] === $filename) {
                    $duplicate_info = $dup;
                    break;
                }
            }

            if ($duplicate_info) {
                $is_replacement = true;
                $existing_document_id = $duplicate_info['existing_id'];

                // Get existing groups for this document
                $stmt = $pdo->prepare("SELECT group_id FROM document_groups WHERE document_id = ?");
                $stmt->execute([$existing_document_id]);
                $existing_groups_result = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $existing_groups = array_map('intval', $existing_groups_result);

                // Delete old embeddings, chunks, and embedding queue entries
                // First get chunk IDs to clean up embedding queue
                $stmt = $pdo->prepare("SELECT id FROM chunks WHERE document_id = ?");
                $stmt->execute([$existing_document_id]);
                $chunk_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // Delete from embedding queue for these chunks
                if (!empty($chunk_ids)) {
                    $placeholders = str_repeat('?,', count($chunk_ids) - 1) . '?';
                    $stmt = $pdo->prepare("DELETE FROM embedding_queue WHERE chunk_id IN ($placeholders)");
                    $stmt->execute($chunk_ids);
                }

                // Delete embeddings (through chunk relationship)
                $stmt = $pdo->prepare("DELETE FROM embeddings WHERE chunk_id IN (SELECT id FROM chunks WHERE document_id = ?)");
                $stmt->execute([$existing_document_id]);

                // Delete chunks
                $stmt = $pdo->prepare("DELETE FROM chunks WHERE document_id = ?");
                $stmt->execute([$existing_document_id]);

                // Update document instead of inserting
                $stmt = $pdo->prepare("
                    UPDATE documents
                    SET content = ?, file_size = ?, updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([$content, $file_size, $existing_document_id]);

                // Remove old group associations and add new ones (preserving existing + adding new)
                $stmt = $pdo->prepare("DELETE FROM document_groups WHERE document_id = ?");
                $stmt->execute([$existing_document_id]);

                $all_groups = array_unique(array_merge($existing_groups, $groups));
            }
        }

        if (!$is_replacement) {
            // Insert document
        $stmt = $pdo->prepare("
            INSERT INTO documents (title, content, group_name, file_type, file_size, document_key)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $document_key = uniqid('doc_', true);
        $stmt->execute([
            pathinfo($filename, PATHINFO_FILENAME),
            $content,
            $first_group_name,
            $file_type,
            $file_size,
            $document_key
        ]);

        $document_id = $pdo->lastInsertId();
            $groups_to_assign = $groups;
        } else {
            // Use existing document ID and merged groups
            $document_id = $existing_document_id;
            $groups_to_assign = $all_groups;
        }

        // Insert/update document-group relationships
        // Deduplicate group IDs to prevent constraint violations
        $unique_group_ids = [];
        foreach ($groups_to_assign as $group_id) {
            if (is_numeric($group_id)) {
                $unique_group_ids[] = (int)$group_id;
            } else {
                // Group name provided - convert to ID
                $name_stmt = $pdo->prepare("SELECT id FROM content_groups WHERE name = ?");
                $name_stmt->execute([$group_id]);
                $group_row = $name_stmt->fetch(PDO::FETCH_ASSOC);
                if ($group_row) {
                    $unique_group_ids[] = (int)$group_row['id'];
                }
            }
        }
        
        // Remove duplicates and ensure we have valid group IDs
        $unique_group_ids = array_unique(array_filter($unique_group_ids, function($id) {
            return $id > 0;
        }));
        
        // Use INSERT OR IGNORE to prevent constraint violations
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO document_groups (document_id, group_id) VALUES (?, ?)");
        foreach ($unique_group_ids as $group_id) {
            $stmt->execute([$document_id, $group_id]);
        }
        
        // Chunk the content
        $chunks = chunkContent($content, $file_type);
        $chunk_count = 0;
        
        foreach ($chunks as $index => $chunk) {
            $stmt = $pdo->prepare("
                INSERT INTO chunks (document_id, chunk_index, content, token_count) 
                VALUES (?, ?, ?, ?)
            ");
            
            $token_count = estimateTokenCount($chunk);
            $stmt->execute([$document_id, $index, $chunk, $token_count]);
            
            $chunk_id = $pdo->lastInsertId();
            
            // Queue for embedding
            $stmt = $pdo->prepare("
                INSERT INTO embedding_queue (chunk_id, content) 
                VALUES (?, ?)
            ");
            $stmt->execute([$chunk_id, $chunk]);
            $chunk_count++;
        }
        
        $results[] = [
            'success' => true, 
            'filename' => $filename, 
            'document_id' => $document_id,
            'chunks' => $chunk_count
        ];
    }
    
    return $results;
}

/**
 * Extract content from uploaded file
 */
function extractContent($file_path, $file_type) {
    switch ($file_type) {
        case 'txt':
        case 'md':
            return file_get_contents($file_path);
            
        case 'pdf':
            // Basic PDF text extraction (requires additional libraries for full support)
            return extractPdfContent($file_path);
            
        case 'doc':
        case 'docx':
            // Basic DOCX extraction
            return extractDocxContent($file_path);
            
        case 'rtf':
            // Basic RTF extraction
            return extractRtfContent($file_path);
            
        default:
            return file_get_contents($file_path);
    }
}

/**
 * Basic PDF content extraction
 * Simple text extraction - for better results, use a dedicated PDF library
 */
function extractPdfContent($file_path) {
    $content = file_get_contents($file_path);
    
    // Look for text objects in PDF
    $text = '';
    if (preg_match_all('/\(([^)]+)\)/', $content, $matches)) {
        foreach ($matches[1] as $match) {
            // Clean up PDF text encoding
            $decoded = '';
            for ($i = 0; $i < strlen($match); $i++) {
                $char = $match[$i];
                if (ord($char) >= 32 && ord($char) <= 126) {
                    $decoded .= $char;
                }
            }
            $text .= $decoded . ' ';
        }
    }
    
    // Fallback: extract readable ASCII text
    if (empty(trim($text))) {
        $text = preg_replace('/[^\x20-\x7E\s]/', '', $content);
        $text = preg_replace('/\s+/', ' ', $text);
    }
    
    return trim($text);
}

/**
 * Basic DOCX content extraction
 * Extracts text from DOCX files using built-in ZipArchive
 */
function extractDocxContent($file_path) {
    if (!class_exists('ZipArchive')) {
        return 'ZipArchive extension not available for DOCX extraction';
    }
    
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== TRUE) {
        return 'Could not open DOCX file';
    }
    
    $content = '';
    
    // Extract main document content
    $document_xml = $zip->getFromName('word/document.xml');
    if ($document_xml !== false) {
        // Parse XML and extract text
        $content .= extractTextFromDocxXml($document_xml);
    }
    
    // Extract headers
    for ($i = 1; $i <= 3; $i++) {
        $header_xml = $zip->getFromName("word/header{$i}.xml");
        if ($header_xml !== false) {
            $content .= "\n" . extractTextFromDocxXml($header_xml);
        }
    }
    
    // Extract footers
    for ($i = 1; $i <= 3; $i++) {
        $footer_xml = $zip->getFromName("word/footer{$i}.xml");
        if ($footer_xml !== false) {
            $content .= "\n" . extractTextFromDocxXml($footer_xml);
        }
    }
    
    $zip->close();
    
    return trim($content);
}

/**
 * Extract text from DOCX XML content
 */
function extractTextFromDocxXml($xml_content) {
    // Remove XML tags but preserve paragraph breaks
    $text = preg_replace('/<w:p[^>]*>/', "\n", $xml_content);
    $text = preg_replace('/<w:br[^>]*>/', "\n", $text);
    $text = preg_replace('/<w:tab[^>]*>/', "\t", $text);
    $text = strip_tags($text);
    
    // Clean up whitespace
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    
    return $text;
}

/**
 * Basic RTF content extraction
 * Extracts plain text from RTF files
 */
function extractRtfContent($file_path) {
    $content = file_get_contents($file_path);
    
    if (strpos($content, '{\rtf') !== 0) {
        return 'Not a valid RTF file';
    }
    
    // Remove RTF control words and groups
    $text = preg_replace('/\{\\\\[^}]*\}/', '', $content);
    $text = preg_replace('/\\\\[a-z]+[0-9]*[ ]?/', '', $text);
    $text = preg_replace('/\{|\}/', '', $text);
    
    // Clean up whitespace and special characters
    $text = preg_replace('/\\\\./', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    
    return trim($text);
}

/**
 * Chunk content intelligently with maximum size limit
 */
function chunkContent($content, $file_type) {
    global $chunk_size, $chunk_overlap;
    
    $chunks = [];
    
    // Split by paragraphs first
    $paragraphs = preg_split('/\n\s*\n/', $content);
    $current_chunk = '';
    
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if (empty($paragraph)) continue;
        
        // If paragraph itself is larger than chunk_size, split it by sentences
        if (strlen($paragraph) > $chunk_size) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
            
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if (empty($sentence)) continue;
                
                // If adding this sentence would exceed chunk_size
                if (strlen($current_chunk . $sentence) > $chunk_size && !empty($current_chunk)) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = getOverlapText($current_chunk, $chunk_overlap) . $sentence;
                } else {
                    $current_chunk .= ($current_chunk ? ' ' : '') . $sentence;
                }
            }
        } else {
            // If adding this paragraph would exceed chunk_size
            if (strlen($current_chunk . "\n\n" . $paragraph) > $chunk_size && !empty($current_chunk)) {
                $chunks[] = trim($current_chunk);
                $current_chunk = getOverlapText($current_chunk, $chunk_overlap) . $paragraph;
            } else {
                $current_chunk .= ($current_chunk ? "\n\n" : '') . $paragraph;
            }
        }
    }
    
    // Add the last chunk
    if (!empty($current_chunk)) {
        $chunks[] = trim($current_chunk);
    }
    
    // Final pass: ensure no chunk exceeds maximum size
    $final_chunks = [];
    foreach ($chunks as $chunk) {
        if (strlen($chunk) <= $chunk_size) {
            $final_chunks[] = $chunk;
        } else {
            // Force split large chunks at word boundaries
            $words = explode(' ', $chunk);
            $current_word_chunk = '';
            
            foreach ($words as $word) {
                if (strlen($current_word_chunk . ' ' . $word) > $chunk_size && !empty($current_word_chunk)) {
                    $final_chunks[] = trim($current_word_chunk);
                    $current_word_chunk = $word;
                } else {
                    $current_word_chunk .= ($current_word_chunk ? ' ' : '') . $word;
                }
            }
            
            if (!empty($current_word_chunk)) {
                $final_chunks[] = trim($current_word_chunk);
            }
        }
    }
    
    return $final_chunks;
}

/**
 * Get overlap text from the end of a chunk
 */
function getOverlapText($text, $overlap_size) {
    if (strlen($text) <= $overlap_size) return $text . "\n\n";
    
    $overlap = substr($text, -$overlap_size);
    
    // Try to break at sentence boundary
    $last_period = strrpos($overlap, '.');
    if ($last_period !== false && $last_period > $overlap_size * 0.5) {
        $overlap = substr($overlap, $last_period + 1);
    }
    
    return trim($overlap) . "\n\n";
}

/**
 * Estimate token count for text
 */
function estimateTokenCount($text) {
    // Rough estimation: 1 token ≈ 4 characters
    return ceil(strlen($text) / 4);
}

/**
 * Process embedding queue
 */
function processEmbeddingQueue($batch_size = 5) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT * FROM embedding_queue 
        WHERE status = 'pending' 
        ORDER BY created_at 
        LIMIT ?
    ");
    $stmt->execute([$batch_size]);
    $queue_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    
    foreach ($queue_items as $item) {
        // Update status to processing
        $pdo->prepare("UPDATE embedding_queue SET status = 'processing' WHERE id = ?")
             ->execute([$item['id']]);

        try {
            $embedding = generateEmbedding($item['content']);

            if ($embedding) {
                // Check if chunk still exists before storing embedding
                $chunk_check_stmt = $pdo->prepare("SELECT id FROM chunks WHERE id = ?");
                $chunk_check_stmt->execute([$item['chunk_id']]);
                if (!$chunk_check_stmt->fetch()) {
                    throw new Exception("Chunk " . $item['chunk_id'] . " was deleted during processing");
                }

                // Store embedding
                $stmt = $pdo->prepare("
                    INSERT INTO embeddings (chunk_id, embedding, model, dimensions)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $item['chunk_id'],
                    serialize($embedding['data']),
                    $embedding['model'],
                    count($embedding['data'])
                ]);

                // Mark as completed
                $pdo->prepare("UPDATE embedding_queue SET status = 'completed' WHERE id = ?")
                     ->execute([$item['id']]);

                $results[] = ['success' => true, 'chunk_id' => $item['chunk_id']];
            } else {
                throw new Exception('Failed to generate embedding - null response');
            }
        } catch (Exception $e) {
            // Mark as failed
            $pdo->prepare("
                UPDATE embedding_queue
                SET status = 'failed', attempts = attempts + 1, error_message = ?
                WHERE id = ?
            ")->execute([$e->getMessage(), $item['id']]);

            $results[] = ['success' => false, 'chunk_id' => $item['chunk_id'], 'error' => $e->getMessage()];
        }
    }
    
    return $results;
}

/**
 * Sanitize text for embedding API calls
 * Fixes UTF-8 encoding issues and removes problematic Unicode characters
 */
function sanitizeEmbeddingText($text) {
    // Fix invalid UTF-8 sequences to prevent JSON encoding failures
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
    }

    // Remove problematic Unicode characters that can cause API issues
    $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text); // Remove emoji
    $text = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $text); // Remove symbols & pictographs
    $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text); // Remove transport & map symbols
    $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text); // Remove misc symbols
    $text = trim($text);

    return $text;
}

/**
 * Generate embedding using configured provider
 */
function generateEmbedding($text, $provider = null) {
    global $embedding_providers, $default_provider;

    if (!$provider) {
        $provider = $default_provider;
    }

    $config = $embedding_providers[$provider];

    switch ($provider) {
        case 'openai':
            return generateOpenAIEmbedding($text, $config);
        case 'gemini':
            return generateGeminiEmbedding($text, $config);
        case 'ollama':
            return generateOllamaEmbedding($text, $config);
        case 'lmstudio':
            return generateLMStudioEmbedding($text, $config);
        default:
            throw new Exception("Unknown embedding provider: $provider");
    }
}

/**
 * Generate embedding using OpenAI API
 */
function generateOpenAIEmbedding($text, $config) {
    if (empty($config['api_key'])) {
        throw new Exception('OpenAI API key not configured');
    }

    $text = sanitizeEmbeddingText($text);

    $data = [
        'input' => $text,
        'model' => $config['model']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['endpoint']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key']
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("OpenAI API error: HTTP $http_code");
    }

    $result = json_decode($response, true);

    if (!isset($result['data'][0]['embedding'])) {
        throw new Exception('Invalid OpenAI API response');
    }

    return [
        'data' => $result['data'][0]['embedding'],
        'model' => $config['model']
    ];
}


/**
 * Generate embedding using Gemini/Google AI API
 */
function generateGeminiEmbedding($text, $config) {
    if (empty($config['api_key'])) {
        throw new Exception('Gemini API key not configured');
    }

    $text = sanitizeEmbeddingText($text);

    $data = [
        'content' => [
            'parts' => [
                ['text' => $text]
            ]
        ]
    ];

    $url = $config['endpoint'] . '?key=' . $config['api_key'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Gemini API error: HTTP $http_code");
    }

    $result = json_decode($response, true);

    if (!isset($result['embedding']['values'])) {
        throw new Exception('Invalid Gemini API response');
    }

    return [
        'data' => $result['embedding']['values'],
        'model' => $config['model']
    ];
}

/**
 * Generate embedding using Ollama API
 */
function generateOllamaEmbedding($text, $config) {
    $text = sanitizeEmbeddingText($text);

    $data = [
        'model' => $config['model'],
        'prompt' => $text
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['endpoint']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Ollama API error: HTTP $http_code");
    }

    $result = json_decode($response, true);

    if (!isset($result['embedding'])) {
        throw new Exception('Invalid Ollama API response');
    }

    return [
        'data' => $result['embedding'],
        'model' => $config['model']
    ];
}

/**
 * Generate embedding using LM Studio API
 */
function generateLMStudioEmbedding($text, $config) {
    $text = sanitizeEmbeddingText($text);

    $data = [
        'input' => $text,
        'model' => $config['model']
    ];

    $headers = ['Content-Type: application/json'];
    if (!empty($config['api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['endpoint']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("LM Studio API error: HTTP $http_code");
    }

    $result = json_decode($response, true);

    if (!isset($result['data'][0]['embedding'])) {
        throw new Exception('Invalid LM Studio API response');
    }

    return [
        'data' => $result['data'][0]['embedding'],
        'model' => $config['model']
    ];
}

/**
 * Perform vector similarity search
 */
function vectorSearch($query, $options = []) {
    global $pdo;

    $limit = $options['limit'] ?? 10;
    $threshold = $options['threshold'] ?? 0.3;
    $group_filter = $options['group'] ?? null;

    // Generate embedding for query
    $query_embedding = generateEmbedding($query);
    if (!$query_embedding) {
        throw new Exception('Failed to generate query embedding');
    }
    
    // Build SQL query
    $sql = "
        SELECT
            c.id as chunk_id,
            c.content,
            c.chunk_index,
            d.id as document_id,
            d.title,
            d.group_name,
            e.embedding,
            e.model
        FROM chunks c
        JOIN documents d ON c.document_id = d.id
        JOIN embeddings e ON c.id = e.chunk_id
    ";

    $params = [];
    if ($group_filter) {
        // Join with document_groups to filter by group
        $sql .= " JOIN document_groups dg ON d.id = dg.document_id
                  JOIN content_groups g ON dg.group_id = g.id
                  WHERE g.name = ?";
        $params[] = $group_filter;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate similarities
    $scored_results = [];
    foreach ($results as $result) {
        $embedding = unserialize($result['embedding']);
        $similarity = cosineSimilarity($query_embedding['data'], $embedding);

        if ($similarity >= $threshold) {
            $result['similarity'] = $similarity;
            $scored_results[] = $result;
        }
    }
    
    // Sort by similarity
    usort($scored_results, function($a, $b) {
        return $b['similarity'] <=> $a['similarity'];
    });
    
    return array_slice($scored_results, 0, $limit);
}

/**
 * Calculate cosine similarity between two vectors
 */
function cosineSimilarity($vec1, $vec2) {
    if (count($vec1) !== count($vec2)) {
        return 0;
    }
    
    $dot_product = 0;
    $norm_a = 0;
    $norm_b = 0;
    
    for ($i = 0; $i < count($vec1); $i++) {
        $dot_product += $vec1[$i] * $vec2[$i];
        $norm_a += $vec1[$i] * $vec1[$i];
        $norm_b += $vec2[$i] * $vec2[$i];
    }
    
    if ($norm_a == 0 || $norm_b == 0) {
        return 0;
    }
    
    return $dot_product / (sqrt($norm_a) * sqrt($norm_b));
}

/**
 * Get all content groups
 */
function getContentGroups() {
    global $pdo;

    $stmt = $pdo->query("SELECT * FROM content_groups ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get groups for a specific document
 */
function getDocumentGroups($document_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT g.id, g.name, g.description, g.color
        FROM content_groups g
        JOIN document_groups dg ON g.id = dg.group_id
        WHERE dg.document_id = ?
        ORDER BY g.name
    ");
    $stmt->execute([$document_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get documents with pagination
 */
function getDocuments($page = 1, $per_page = 20, $group_filter = null) {
    global $pdo;

    $offset = ($page - 1) * $per_page;

    // Use correlated subqueries to compute counts without duplication
    $sql = "SELECT d.*,
                   (SELECT COUNT(*) FROM chunks c WHERE c.document_id = d.id) AS chunk_count,
                   (SELECT COUNT(*) FROM embeddings e JOIN chunks c2 ON e.chunk_id = c2.id WHERE c2.document_id = d.id) AS embedding_count,
                   (SELECT COUNT(*) FROM embedding_queue q JOIN chunks c3 ON q.chunk_id = c3.id WHERE c3.document_id = d.id AND q.status = 'pending') AS pending_count
            FROM documents d";
    $params = [];

    if ($group_filter) {
        // Join with document_groups to filter by group
        $sql .= " JOIN document_groups dg ON d.id = dg.document_id
                  JOIN content_groups g ON dg.group_id = g.id
                  WHERE g.name = ?";
        $params[] = $group_filter;
    }

    $sql .= " GROUP BY d.id ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Process embedding queue for selected documents
 */
function processEmbeddingQueueForDocs($document_ids = [], $limit_total = null, $batch_size = 10, $batch_index = 0) {
    global $pdo;
    if (empty($document_ids)) return ['processed' => [], 'errors' => [], 'total' => 0, 'completed' => 0, 'has_more' => false];

    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($document_ids), '?'));
    $sql = "SELECT q.* FROM embedding_queue q
            JOIN chunks c ON q.chunk_id = c.id
            WHERE q.status = 'pending' AND c.document_id IN ($placeholders)
            ORDER BY q.created_at";
    if ($limit_total !== null) {
        $sql .= " LIMIT " . intval($limit_total);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($document_ids);
    $queue_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_items = count($queue_items);
    $start_index = $batch_index * $batch_size;
    $end_index = min($start_index + $batch_size, $total_items);
    $batch_items = array_slice($queue_items, $start_index, $batch_size);

    $results = [];
    foreach ($batch_items as $item) {
        // mark processing
        $pdo->prepare("UPDATE embedding_queue SET status = 'processing' WHERE id = ?")->execute([$item['id']]);
        try {
            $embedding = generateEmbedding($item['content']);
            if ($embedding) {
                $stmt = $pdo->prepare("INSERT INTO embeddings (chunk_id, embedding, model, dimensions) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $item['chunk_id'],
                    serialize($embedding['data']),
                    $embedding['model'],
                    count($embedding['data'])
                ]);
                $pdo->prepare("UPDATE embedding_queue SET status = 'completed' WHERE id = ?")->execute([$item['id']]);
                $results[] = ['success' => true, 'chunk_id' => $item['chunk_id']];
            } else {
                throw new Exception('Failed to generate embedding');
            }
        } catch (Exception $e) {
            $pdo->prepare("UPDATE embedding_queue SET status = 'failed', attempts = attempts + 1, error_message = ? WHERE id = ?")
                ->execute([$e->getMessage(), $item['id']]);
            $results[] = ['success' => false, 'chunk_id' => $item['chunk_id'], 'error' => $e->getMessage()];
        }
    }

    $completed_count = $end_index;
    $has_more = $end_index < $total_items;

    return [
        'results' => $results,
        'total' => $total_items,
        'completed' => $completed_count,
        'batch_index' => $batch_index,
        'has_more' => $has_more,
        'progress_percent' => $total_items > 0 ? round(($completed_count / $total_items) * 100, 1) : 100
    ];
}

/**
 * Delete document and associated data
 */
function deleteDocument($document_id) {
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Delete from embedding queue
        $pdo->prepare("DELETE FROM embedding_queue WHERE chunk_id IN (SELECT id FROM chunks WHERE document_id = ?)")
             ->execute([$document_id]);

        // Delete embeddings
        $pdo->prepare("DELETE FROM embeddings WHERE chunk_id IN (SELECT id FROM chunks WHERE document_id = ?)")
             ->execute([$document_id]);

        // Delete chunks
        $pdo->prepare("DELETE FROM chunks WHERE document_id = ?")
             ->execute([$document_id]);

        // Delete document
        $pdo->prepare("DELETE FROM documents WHERE id = ?")
             ->execute([$document_id]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        return false;
    }
}

/**
 * Generate password hash for configuration
 * Usage: Add ?generate_password=your_password to URL or run from command line
 */
if (isset($_GET['generate_password']) && !empty($_GET['generate_password'])) {
    $plain_password = $_GET['generate_password'];
    
    echo "<h2>Password Hash Generator</h2>";
    echo "<p>Password: <strong>" . htmlspecialchars($plain_password) . "</strong></p>";
    echo "<h3>Configuration Options:</h3>";
    echo "<p><strong>Plain text (not recommended):</strong><br>";
    echo "<code>\$password = '" . htmlspecialchars($plain_password) . "';</code></p>";
    
    echo "<p><strong>MD5 hash:</strong><br>";
    echo "<code>\$password = 'md5:" . md5($plain_password) . "';</code></p>";
    
    echo "<p><strong>SHA256 hash (recommended):</strong><br>";
    echo "<code>\$password = 'sha256:" . hash('sha256', $plain_password) . "';</code></p>";
    
    if (function_exists('password_hash')) {
        echo "<p><strong>Bcrypt hash (most secure):</strong><br>";
        echo "<code>\$password = 'bcrypt:" . password_hash($plain_password, PASSWORD_DEFAULT) . "';</code></p>";
    }
    
    echo "<p><a href='" . $_SERVER['PHP_SELF'] . "'>← Back to VectorLiteAdmin</a></p>";
    exit;
}

// Command line password generation
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'generate-password' && isset($argv[2])) {
    $plain_password = $argv[2];
    
    echo "Password Hash Generator\n";
    echo "======================\n";
    echo "Password: " . $plain_password . "\n\n";
    
    echo "Configuration options:\n\n";
    
    echo "Plain text (not recommended):\n";
    echo "\$password = '" . $plain_password . "';\n\n";
    
    echo "MD5 hash:\n";
    echo "\$password = 'md5:" . md5($plain_password) . "';\n\n";
    
    echo "SHA256 hash (recommended):\n";
    echo "\$password = 'sha256:" . hash('sha256', $plain_password) . "';\n\n";
    
    if (function_exists('password_hash')) {
        echo "Bcrypt hash (most secure):\n";
        echo "\$password = 'bcrypt:" . password_hash($plain_password, PASSWORD_DEFAULT) . "';\n\n";
    }
    
    exit;
}

/**
 * Check if this is a first run that needs setup wizard
 */
function needsSetupWizard() {
    global $password, $directory, $embedding_providers;
    
    // Check if config file exists
    $config_exists = file_exists(__DIR__ . '/vectorliteadmin.config.php');
    
    // Check if password is still default
    $default_password = ($password === 'admin');
    
    // Check if database directory exists and has databases
    $has_databases = false;
    if (is_dir($directory)) {
        $files = glob($directory . '/*.sqlite');
        $has_databases = !empty($files);
    }
    
    // Check if any embedding provider is configured
    $has_embedding_config = false;
    foreach ($embedding_providers as $provider) {
        if (!empty($provider['api_key']) || $provider['name'] === 'Ollama') {
            $has_embedding_config = true;
            break;
        }
    }
    
    // Trigger wizard only if:
    // 1. No config file AND default password (true first run)
    // 2. No databases exist AND no embedding provider configured (true first run)
    // Note: Removed explicit ?setup=1 trigger - use integrated settings instead
    return (!$config_exists && $default_password) || 
           (!$has_databases && !$has_embedding_config);
}

/**
 * Handle setup wizard form submission
 */
function handleSetupWizard() {
    if (!isset($_POST['setup_step'])) return false;
    
    $step = $_POST['setup_step'];
    
    if ($step === 'complete') {
        // Generate configuration file
        $config_content = generateConfigFile($_POST);
        
        // Write config file
        $config_path = __DIR__ . '/vectorliteadmin.config.php';
        if (file_put_contents($config_path, $config_content)) {
            // Create database directory if needed
            $db_dir = $_POST['database_directory'] ?? './databases';
            if (!is_dir($db_dir)) {
                mkdir($db_dir, 0755, true);
            }
            
            // Create the first database with custom name
            $db_name = $_POST['database_name'] ?? 'my-vector-db';
            $db_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $db_name); // Sanitize
            $first_db_path = $db_dir . '/' . $db_name . '.sqlite';
            if (!file_exists($first_db_path)) {
                createSampleDatabase($first_db_path);
            }
            
            // Redirect to main application
            header('Location: ' . $_SERVER['PHP_SELF'] . '?setup_complete=1');
            exit;
        } else {
            return ['error' => 'Failed to create configuration file. Check file permissions.'];
        }
    }
    
    return false;
}

/**
 * Create a new empty database with schema
 */
function createEmptyDatabase($db_path) {
    try {
        $pdo = new PDO("sqlite:$db_path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initialize vector schema
        $schema = "
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            metadata TEXT,
            document_key TEXT UNIQUE,
            group_name TEXT DEFAULT 'default',
            file_type TEXT,
            file_size INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS chunks (
            id INTEGER PRIMARY KEY,
            document_id INTEGER,
            chunk_index INTEGER,
            content TEXT NOT NULL,
            metadata TEXT,
            token_count INTEGER,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS embeddings (
            id INTEGER PRIMARY KEY,
            chunk_id INTEGER,
            embedding BLOB,
            model TEXT,
            dimensions INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS embedding_queue (
            id INTEGER PRIMARY KEY,
            chunk_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS content_groups (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            color TEXT DEFAULT '#007cba',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS document_groups (
            id INTEGER PRIMARY KEY,
            document_id INTEGER NOT NULL,
            group_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES content_groups(id) ON DELETE CASCADE,
            UNIQUE(document_id, group_id)
        );

        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type TEXT DEFAULT 'string',
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE VIRTUAL TABLE IF NOT EXISTS chunk_index USING fts5(
            content,
            content_rowid='id',
            content='chunks'
        );
        
        INSERT OR IGNORE INTO content_groups (name, description) VALUES ('default', 'Default content group');
        ";
        
        $pdo->exec($schema);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create a sample database with initial content
 */
function createSampleDatabase($db_path) {
    try {
        $pdo = new PDO("sqlite:$db_path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initialize vector schema
        $schema = "
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            metadata TEXT,
            document_key TEXT UNIQUE,
            group_name TEXT DEFAULT 'default',
            file_type TEXT,
            file_size INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS chunks (
            id INTEGER PRIMARY KEY,
            document_id INTEGER,
            chunk_index INTEGER,
            content TEXT NOT NULL,
            metadata TEXT,
            token_count INTEGER,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS embeddings (
            id INTEGER PRIMARY KEY,
            chunk_id INTEGER,
            embedding BLOB,
            model TEXT,
            dimensions INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS embedding_queue (
            id INTEGER PRIMARY KEY,
            chunk_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS content_groups (
            id INTEGER PRIMARY KEY,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            color TEXT DEFAULT '#007cba',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS document_groups (
            id INTEGER PRIMARY KEY,
            document_id INTEGER NOT NULL,
            group_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES content_groups(id) ON DELETE CASCADE,
            UNIQUE(document_id, group_id)
        );

        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type TEXT DEFAULT 'string',
            description TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE VIRTUAL TABLE IF NOT EXISTS chunk_index USING fts5(
            content,
            content_rowid='id',
            content='chunks'
        );
        
        INSERT OR IGNORE INTO content_groups (name, description) VALUES ('default', 'Default content group');
        INSERT OR IGNORE INTO content_groups (name, description) VALUES ('getting-started', 'Getting Started Guide');
        ";
        
        $pdo->exec($schema);
        
        // Add sample content
        $sample_content = "Welcome to VectorLiteAdmin!\n\nThis is your vector database management system. Here's how to get started:\n\n1. Upload Documents: Use the upload panel to add text files, PDFs, or other documents to your database.\n\n2. Process Embeddings: After uploading, click 'Process Embeddings' to generate vector embeddings for your content.\n\n3. Search: Use the vector search to find relevant information based on semantic similarity.\n\n4. Manage Content: Organize your documents into groups and manage your knowledge base.\n\nVectorLiteAdmin supports multiple embedding providers including OpenAI, Ollama, and LM Studio. You can configure these in your settings.\n\nFor more information, check the documentation or visit the project repository.";
        
        $stmt = $pdo->prepare("
            INSERT INTO documents (title, content, group_name, file_type, file_size, document_key) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            'Getting Started with VectorLiteAdmin',
            $sample_content,
            'getting-started',
            'txt',
            strlen($sample_content),
            'sample_' . uniqid()
        ]);

        // Create document-group relationship for the sample document
        $document_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO document_groups (document_id, group_id) VALUES (?, (SELECT id FROM content_groups WHERE name = 'getting-started'))");
        $stmt->execute([$document_id]);

        // Also chunk the sample content and queue for embeddings so pending count reflects reality
        $chunks = chunkContent($sample_content, 'txt');
        foreach ($chunks as $index => $chunk) {
            $stmt = $pdo->prepare("INSERT INTO chunks (document_id, chunk_index, content, token_count) VALUES (?, ?, ?, ?)");
            $token_count = estimateTokenCount($chunk);
            $stmt->execute([$document_id, $index, $chunk, $token_count]);
            $chunk_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO embedding_queue (chunk_id, content) VALUES (?, ?)");
            $stmt->execute([$chunk_id, $chunk]);
        }

        // Ensure all documents have at least one group association (migration for existing databases)
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO document_groups (document_id, group_id)
            SELECT d.id,
                   CASE
                       WHEN d.group_name = 'getting-started' THEN (SELECT id FROM content_groups WHERE name = 'getting-started')
                       ELSE (SELECT id FROM content_groups WHERE name = 'default')
                   END
            FROM documents d
            WHERE NOT EXISTS (
                SELECT 1 FROM document_groups dg WHERE dg.document_id = d.id
            )
        ");
        $stmt->execute();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Generate configuration file content
 */
function generateConfigFile($data) {
    $password = $data['password'] ?? 'admin';
    $hash_type = $data['password_hash'] ?? 'plain';
    $selected_provider = $data['default_provider'] ?? 'openai';
    
    // Generate password hash
    switch ($hash_type) {
        case 'md5':
            $password_line = "\$password = 'md5:" . md5($password) . "';";
            break;
        case 'sha256':
            $password_line = "\$password = 'sha256:" . hash('sha256', $password) . "';";
            break;
        case 'bcrypt':
            $password_line = "\$password = 'bcrypt:" . password_hash($password, PASSWORD_DEFAULT) . "';";
            break;
        default:
            $password_line = "\$password = '" . addslashes($password) . "';";
    }
    
    $config = "<?php\n";
    $config .= "//\n";
    $config .= "// VectorLiteAdmin Configuration File\n";
    $config .= "// Generated by Setup Wizard on " . date('Y-m-d H:i:s') . "\n";
    $config .= "//\n\n";
    
    // Password configuration
    $config .= "// Password configuration\n";
    $config .= $password_line . "\n\n";
    
    // Database settings
    $config .= "// Database settings\n";
    $config .= "\$directory = '" . addslashes($data['database_directory'] ?? './databases') . "';\n";
    $config .= "\$subdirectories = " . (isset($data['scan_subdirectories']) ? 'true' : 'false') . ";\n\n";
    
    // Upload settings
    $config .= "// Upload settings (chunk_size is maximum, actual chunks may be smaller)\n";
    $config .= "\$max_upload_size = " . (int)($data['max_upload_size'] ?? 50) . "; // MB\n";
    $config .= "\$chunk_size = " . (int)($data['chunk_size'] ?? 1000) . "; // Maximum chunk size\n";
    $config .= "\$chunk_overlap = " . (int)($data['chunk_overlap'] ?? 200) . ";\n\n";
    
    // Advanced settings
    $config .= "// Advanced settings\n";
    $config .= "\$theme = '" . addslashes($data['theme'] ?? 'default') . "';\n";
    $config .= "\$enable_debug = " . (isset($data['enable_debug']) ? 'true' : 'false') . ";\n";
    $config .= "\$log_queries = " . (isset($data['log_queries']) ? 'true' : 'false') . ";\n\n";
    
    // Embedding provider settings - preserve existing configured providers and add current selection
    $config .= "// Embedding provider settings\n";
    $config .= "\$default_provider = '" . addslashes($selected_provider) . "';\n\n";

    // Determine which providers to configure based on existing settings and current selection
    $providers_to_configure = [];

    // Include providers that have actual configuration values set
    if (!empty($data['openai_api_key'])) {
        $providers_to_configure[] = 'openai';
    }
    if (!empty($data['gemini_api_key'])) {
        $providers_to_configure[] = 'gemini';
    }
    // Include Ollama if it has custom endpoint or model settings
    if (!empty($data['ollama_endpoint']) || !empty($data['ollama_model'])) {
        $providers_to_configure[] = 'ollama';
    }
    // Include LM Studio if it has custom endpoint or API key (API key can be empty if endpoint is set)
    if (!empty($data['lmstudio_endpoint']) || !empty($data['lmstudio_api_key'])) {
        $providers_to_configure[] = 'lmstudio';
    }

    // Always include the currently selected provider if not already included
    if (!in_array($selected_provider, $providers_to_configure)) {
        $providers_to_configure[] = $selected_provider;
    }

    // Configure only the providers that should be included
    foreach ($providers_to_configure as $provider) {
        switch ($provider) {
            case 'openai':
                if (!empty($data['openai_api_key'])) {
                    $config .= "// OpenAI configuration\n";
                    $config .= "\$embedding_providers['openai']['api_key'] = '" . addslashes($data['openai_api_key']) . "';\n";
                    $config .= "\$embedding_providers['openai']['model'] = '" . addslashes($data['openai_model'] ?? 'text-embedding-3-small') . "';\n\n";
                }
                break;

            case 'gemini':
                if (!empty($data['gemini_api_key'])) {
                    $config .= "// Gemini configuration\n";
                    $config .= "\$embedding_providers['gemini']['api_key'] = '" . addslashes($data['gemini_api_key']) . "';\n";
                    $config .= "\$embedding_providers['gemini']['model'] = '" . addslashes($data['gemini_model'] ?? 'gemini-embedding-001') . "';\n\n";
                }
                break;

            case 'ollama':
                $config .= "// Ollama configuration\n";
                $config .= "\$embedding_providers['ollama']['endpoint'] = '" . addslashes($data['ollama_endpoint'] ?? 'http://localhost:11434/api/embeddings') . "';\n";
                $config .= "\$embedding_providers['ollama']['model'] = '" . addslashes($data['ollama_model'] ?? 'nomic-embed-text') . "';\n\n";
                break;

            case 'lmstudio':
                $config .= "// LM Studio configuration\n";
                $config .= "\$embedding_providers['lmstudio']['endpoint'] = '" . addslashes($data['lmstudio_endpoint'] ?? 'http://localhost:1234/v1/embeddings') . "';\n";
                // Always include API key for LM Studio, even if empty
                $config .= "\$embedding_providers['lmstudio']['api_key'] = '" . addslashes($data['lmstudio_api_key'] ?? '') . "';\n";
                $config .= "\n";
                break;
        }
    }
    
    // Security settings
    $config .= "// Security settings\n";
    $config .= "\$session_timeout = " . (int)($data['session_timeout'] ?? 3600) . ";\n\n";
    
    $config .= "?>";
    
    return $config;
}

/**
 * Load existing configuration values
 */
function loadExistingConfig() {
    $config_path = __DIR__ . '/vectorliteadmin.config.php';
    if (!file_exists($config_path)) {
        return [];
    }
    
    // Parse the config file to extract values
    $content = file_get_contents($config_path);
    $config = [];
    
    // Extract password
    if (preg_match('/\$password\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['password'] = $matches[1];
    }
    
    // Extract directory
    if (preg_match('/\$directory\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['directory'] = $matches[1];
    }
    
    // Extract subdirectories
    if (preg_match('/\$subdirectories\s*=\s*(true|false);/', $content, $matches)) {
        $config['subdirectories'] = $matches[1] === 'true';
    }
    
    // Extract max_upload_size (convert from bytes to MB)
    if (preg_match('/\$max_upload_size\s*=\s*(\d+)\s*\*\s*1024\s*\*\s*1024;/', $content, $matches)) {
        $config['max_upload_size'] = (int)$matches[1];
    }
    
    // Extract chunk_size
    if (preg_match('/\$chunk_size\s*=\s*(\d+);/', $content, $matches)) {
        $config['chunk_size'] = (int)$matches[1];
    }
    
    // Extract chunk_overlap
    if (preg_match('/\$chunk_overlap\s*=\s*(\d+);/', $content, $matches)) {
        $config['chunk_overlap'] = (int)$matches[1];
    }
    
    // Extract default_provider
    if (preg_match('/\$default_provider\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['default_provider'] = $matches[1];
    }
    
    // Extract session_timeout
    if (preg_match('/\$session_timeout\s*=\s*(\d+);/', $content, $matches)) {
        $config['session_timeout'] = (int)$matches[1];
    }
    
    // Extract theme
    if (preg_match('/\$theme\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['theme'] = $matches[1];
    }
    
    // Extract debug settings
    if (preg_match('/\$enable_debug\s*=\s*(true|false);/', $content, $matches)) {
        $config['enable_debug'] = $matches[1] === 'true';
    }
    
    if (preg_match('/\$log_queries\s*=\s*(true|false);/', $content, $matches)) {
        $config['log_queries'] = $matches[1] === 'true';
    }
    
    // Extract API keys and provider settings
    if (preg_match('/\$embedding_providers\[\'openai\'\]\[\'api_key\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['openai_api_key'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'openai\'\]\[\'model\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['openai_model'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'gemini\'\]\[\'api_key\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['gemini_api_key'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'gemini\'\]\[\'model\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['gemini_model'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'ollama\'\]\[\'endpoint\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['ollama_endpoint'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'ollama\'\]\[\'model\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['ollama_model'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'lmstudio\'\]\[\'endpoint\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['lmstudio_endpoint'] = $matches[1];
    }

    if (preg_match('/\$embedding_providers\[\'lmstudio\'\]\[\'api_key\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $content, $matches)) {
        $config['lmstudio_api_key'] = $matches[1];
    }
    
    return $config;
}

/**
 * Update configuration file with new values
 */
function updateConfigFile($new_values) {
    $existing_config = loadExistingConfig();
    $merged_config = array_merge($existing_config, $new_values);
    
    $config_content = generateConfigFile($merged_config);
    $config_path = __DIR__ . '/vectorliteadmin.config.php';
    
    return file_put_contents($config_path, $config_content) !== false;
}

/**
 * Handle settings form submission
 */
function handleSettingsUpdate() {
    global $password;
    
    if (!isset($_POST['update_settings'])) {
        return false;
    }
    
    // If password change is requested, validate current password
    if (!empty($_POST['password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['password_confirm'] ?? '';
        
        // Validate current password
        if (!verifyPassword($current_password, $password)) {
            return ['error' => 'Current password is incorrect.'];
        }
        
        // Validate new password confirmation
        if ($new_password !== $confirm_password) {
            return ['error' => 'New password and confirmation do not match.'];
        }
        
        // Validate new password length
        if (strlen($new_password) < 6) {
            return ['error' => 'New password must be at least 6 characters long.'];
        }
    }
    
    $new_values = [
        'password' => $_POST['password'] ?? '',
        'password_hash' => $_POST['password_hash'] ?? 'plain',
        'database_directory' => $_POST['database_directory'] ?? './databases',
        'scan_subdirectories' => isset($_POST['scan_subdirectories']),
        'max_upload_size' => (int)($_POST['max_upload_size'] ?? 50),
        'chunk_size' => (int)($_POST['chunk_size'] ?? 1000),
        'chunk_overlap' => (int)($_POST['chunk_overlap'] ?? 200),
        'session_timeout' => (int)($_POST['session_timeout'] ?? 3600),
        'default_provider' => $_POST['default_provider'] ?? 'openai',
        'theme' => $_POST['theme'] ?? 'default',
        'enable_debug' => isset($_POST['enable_debug']),
        'log_queries' => isset($_POST['log_queries'])
    ];
    
    // Add provider-specific settings - only update the currently selected provider
    // Other provider settings will be preserved from existing config
    $selected_provider = $new_values['default_provider'];
    switch ($selected_provider) {
        case 'openai':
            $new_values['openai_api_key'] = $_POST['openai_api_key'] ?? '';
            $new_values['openai_model'] = $_POST['openai_model'] ?? 'text-embedding-3-small';
            break;
        case 'gemini':
            $new_values['gemini_api_key'] = $_POST['gemini_api_key'] ?? '';
            $new_values['gemini_model'] = $_POST['gemini_model'] ?? 'gemini-embedding-001';
            break;
        case 'ollama':
            $new_values['ollama_endpoint'] = $_POST['ollama_endpoint'] ?? 'http://localhost:11434/api/embeddings';
            $new_values['ollama_model'] = $_POST['ollama_model'] ?? 'nomic-embed-text';
            break;
        case 'lmstudio':
            $new_values['lmstudio_endpoint'] = $_POST['lmstudio_endpoint'] ?? 'http://localhost:1234/v1/embeddings';
            $new_values['lmstudio_api_key'] = $_POST['lmstudio_api_key'] ?? '';
            break;
    }
    
    if (updateConfigFile($new_values)) {
        return ['success' => 'Configuration updated successfully. Please refresh the page.'];
    } else {
        return ['error' => 'Failed to update configuration file. Check file permissions.'];
    }
}

/**
 * Display setup wizard
 */
function displaySetupWizard() {
    $error = handleSetupWizard();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>VectorLiteAdmin - Setup Wizard</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
            .wizard-container { max-width: 800px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
            .wizard-header { background: #007cba; color: white; padding: 30px; text-align: center; }
            .wizard-header h1 { margin: 0; font-size: 2.5em; }
            .wizard-header p { margin: 10px 0 0 0; opacity: 0.9; }
            .wizard-content { padding: 40px; }
            .step { display: none; }
            .step.active { display: block; }
            .step h2 { color: #007cba; margin-top: 0; }
            .form-group { margin-bottom: 25px; }
            .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
            .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
            .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #007cba; outline: none; box-shadow: 0 0 5px rgba(0,124,186,0.3); }
            .form-group small { color: #666; font-size: 12px; margin-top: 5px; display: block; }
            .checkbox-group { display: flex; align-items: center; }
            .checkbox-group input[type="checkbox"] { width: auto; margin-right: 10px; }
            .btn { background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; margin-right: 10px; }
            .btn:hover { background: #005a8b; }
            .btn-secondary { background: #6c757d; }
            .btn-secondary:hover { background: #545b62; }
            .wizard-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; }
            .progress-bar { background: #f0f0f0; height: 4px; border-radius: 2px; margin-bottom: 30px; }
            .progress-fill { background: #007cba; height: 100%; border-radius: 2px; transition: width 0.3s; }
            .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
            .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .provider-option { border: 1px solid #ddd; padding: 20px; margin-bottom: 15px; border-radius: 6px; cursor: pointer; transition: all 0.2s; }
            .provider-option:hover { border-color: #007cba; }
            .provider-option.selected { border-color: #007cba; background: #f8f9fa; }
            .provider-option h4 { margin: 0 0 10px 0; color: #007cba; }
            .provider-option p { margin: 0; color: #666; font-size: 14px; }
            .two-column { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            @media (max-width: 768px) { .two-column { grid-template-columns: 1fr; } }
            
            /* Dark theme styles */
            body.dark-theme { background: #1a1a1a; color: #e0e0e0; }
            .dark-theme .wizard-container { background: #2d2d2d; }
            .dark-theme .wizard-header { background: #1e3a5f; }
            .dark-theme .step h2 { color: #4a9eff; }
            .dark-theme .form-group label { color: #e0e0e0; }
            .dark-theme .form-group input, .dark-theme .form-group select { background: #3a3a3a; border-color: #555; color: #e0e0e0; }
            .dark-theme .form-group input:focus, .dark-theme .form-group select:focus { border-color: #4a9eff; }
            .dark-theme .btn { background: #4a9eff; }
            .dark-theme .btn:hover { background: #357abd; }
            .dark-theme .alert-success { background: #1e3a1e; color: #90ee90; border-color: #2d5a2d; }
            .dark-theme details { color: #e0e0e0; }
            .dark-theme details > div { background: #3a3a3a; }
        </style>
    </head>
    <body>
        <div class="wizard-container">
            <div class="wizard-header">
                <h1>🚀 VectorLiteAdmin</h1>
                <p>Welcome! Let's set up your vector database management system</p>
            </div>
            
            <div class="wizard-content">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error['error']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill" style="width: 20%"></div>
                </div>
                
                <form method="post" id="wizardForm">
                    <input type="hidden" name="setup_step" value="complete">
                    
                    <!-- Step 1: Security & Advanced Settings -->
                    <div class="step active" id="step1">
                        <h2>Step 1: Security & Advanced Settings</h2>
                        <p>First, let's secure your installation and configure advanced options.</p>
                        
                        <div class="form-group">
                            <label for="password">Admin Password</label>
                            <input type="password" id="password" name="password" required minlength="6">
                            <small>Choose a strong password (minimum 6 characters)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="password_confirm">Confirm Password</label>
                            <input type="password" id="password_confirm" required minlength="6">
                            <small>Re-enter your password to confirm</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="password_hash">Password Security Level</label>
                            <select id="password_hash" name="password_hash">
                                <option value="plain">Plain Text (not recommended)</option>
                                <option value="md5">MD5 Hash (basic security)</option>
                                <option value="sha256" selected>SHA256 Hash (recommended)</option>
                                <option value="bcrypt">Bcrypt Hash (maximum security)</option>
                            </select>
                            <small>Higher security levels protect your password better</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="theme">Interface Theme</label>
                            <select id="theme" name="theme" onchange="applyThemePreview(this.value)">
                                <option value="default">Default</option>
                                <option value="dark">Dark</option>
                                <option value="light">Light</option>
                            </select>
                            <small>Choose your preferred interface theme - changes apply immediately</small>
                        </div>
                        
                        <details style="margin-top: 20px;">
                            <summary style="cursor: pointer; font-weight: bold; color: #666; padding: 10px; background: #f8f9fa; border-radius: 4px;">🔧 Advanced Options (Optional)</summary>
                            <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 3px solid #007cba;">
                                <div class="form-group checkbox-group">
                                    <input type="checkbox" id="enable_debug" name="enable_debug">
                                    <label for="enable_debug">Enable Debug Mode</label>
                                    <small style="display: block; margin-left: 25px; color: #666;">Show detailed error messages (not recommended for production)</small>
                                </div>
                                
                                <div class="form-group checkbox-group">
                                    <input type="checkbox" id="log_queries" name="log_queries">
                                    <label for="log_queries">Log Database Queries</label>
                                    <small style="display: block; margin-left: 25px; color: #666;">Log all database queries for debugging (may impact performance)</small>
                                </div>
                            </div>
                        </details>
                    </div>
                    
                    <!-- Step 2: Database Configuration -->
                    <div class="step" id="step2">
                        <h2>Step 2: Database Configuration</h2>
                        <p>Configure where your vector databases will be stored and name your first database.</p>
                        
                        <div class="form-group">
                            <label for="database_name">First Database Name</label>
                            <input type="text" id="database_name" name="database_name" value="my-vector-db" required pattern="[a-zA-Z0-9_\-]+" maxlength="50">
                            <small>Name for your first database (letters, numbers, hyphens, and underscores only)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="database_directory">Database Directory</label>
                            <input type="text" id="database_directory" name="database_directory" value="./databases">
                            <small>Directory where SQLite databases will be stored (relative to this file)</small>
                        </div>
                        
                        <div class="form-group checkbox-group">
                            <input type="checkbox" id="scan_subdirectories" name="scan_subdirectories" checked>
                            <label for="scan_subdirectories">Scan subdirectories for databases</label>
                        </div>
                        
                        <div class="two-column">
                            <div class="form-group">
                                <label for="max_upload_size">Max Upload Size (MB)</label>
                                <input type="number" id="max_upload_size" name="max_upload_size" value="50" min="1" max="500">
                                <small>Maximum file size for uploads in megabytes</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_timeout">Session Timeout (seconds)</label>
                                <input type="number" id="session_timeout" name="session_timeout" value="3600" min="300">
                                <small>How long before automatic logout (3600 = 1 hour)</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Content Processing -->
                    <div class="step" id="step3">
                        <h2>Step 3: Content Processing</h2>
                        <p>Configure how documents are processed and chunked for embedding.</p>
                        
                        <div class="two-column">
                            <div class="form-group">
                                <label for="chunk_size">Maximum Chunk Size (characters)</label>
                                <input type="number" id="chunk_size" name="chunk_size" value="1000" min="100" max="5000">
                                <small>Maximum size of text chunks - actual chunks may be smaller to preserve context</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="chunk_overlap">Chunk Overlap (characters)</label>
                                <input type="number" id="chunk_overlap" name="chunk_overlap" value="200" min="0" max="1000">
                                <small>Overlap between chunks to maintain context</small>
                            </div>
                        </div>
                        
                        <div class="alert alert-success">
                            <strong>💡 Tip:</strong> Smaller chunks (500-1000 chars) work better for precise search, 
                            while larger chunks (1500-3000 chars) preserve more context.
                        </div>
                    </div>
                    
                    <!-- Step 4: Embedding Provider -->
                    <div class="step" id="step4">
                        <h2>Step 4: Embedding Provider</h2>
                        <p>Choose and configure your embedding provider. You can change this later.</p>
                        
                        <div class="form-group">
                            <label>Select Primary Provider</label>
                            
                            <div class="provider-option" onclick="selectProvider('openai', this)">
                                <h4>🤖 OpenAI (Cloud)</h4>
                                <p>High-quality embeddings via OpenAI API. Requires API key and internet connection.</p>
                            </div>

                            <div class="provider-option" onclick="selectProvider('gemini', this)">
                                <h4>🤖 Gemini (Cloud)</h4>
                                <p>High-quality embeddings via Google AI. Requires Google AI API key.</p>
                            </div>
                            
                            <div class="provider-option" onclick="selectProvider('ollama', this)">
                                <h4>🏠 Ollama (Local)</h4>
                                <p>Run embedding models locally. Free but requires Ollama installation.</p>
                            </div>
                            
                            <div class="provider-option" onclick="selectProvider('lmstudio', this)">
                                <h4>🖥️ LM Studio (Local)</h4>
                                <p>Use LM Studio for local embeddings. Requires LM Studio setup.</p>
                            </div>

                            <input type="hidden" id="default_provider" name="default_provider" value="openai">
                        </div>
                        
                        <!-- OpenAI Configuration -->
                        <div id="openai_config" class="provider-config">
                            <div class="form-group">
                                <label for="openai_api_key">OpenAI API Key</label>
                                <input type="password" id="openai_api_key" name="openai_api_key" placeholder="sk-...">
                                <small>Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></small>
                            </div>
                            
                            <div class="form-group">
                                <label for="openai_model">Model</label>
                                <select id="openai_model" name="openai_model">
                                    <option value="text-embedding-3-small">text-embedding-3-small (recommended)</option>
                                    <option value="text-embedding-3-large">text-embedding-3-large (higher quality)</option>
                                    <option value="text-embedding-ada-002">text-embedding-ada-002 (legacy)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Gemini Configuration -->
                        <div id="gemini_config" class="provider-config" style="display: none;">
                            <div class="form-group">
                                <label for="gemini_api_key">Google AI API Key</label>
                                <input type="password" id="gemini_api_key" name="gemini_api_key" placeholder="AIza...">
                                <small>Get your API key from <a href="https://makersuite.google.com/app/apikey" target="_blank">Google AI Studio</a></small>
                            </div>

                            <div class="form-group">
                                <label for="gemini_model">Model</label>
                                <select id="gemini_model" name="gemini_model">
                                    <option value="gemini-embedding-001">gemini-embedding-001 (recommended)</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Ollama Configuration -->
                        <div id="ollama_config" class="provider-config" style="display: none;">
                            <div class="form-group">
                                <label for="ollama_endpoint">Ollama Endpoint</label>
                                <input type="url" id="ollama_endpoint" name="ollama_endpoint" value="http://localhost:11434/api/embeddings">
                                <small>Default Ollama API endpoint</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="ollama_model">Model</label>
                                <input type="text" id="ollama_model" name="ollama_model" value="nomic-embed-text">
                                <small>Embedding model name (must be installed in Ollama)</small>
                            </div>
                        </div>
                        
                        <!-- LM Studio Configuration -->
                        <div id="lmstudio_config" class="provider-config" style="display: none;">
                            <div class="form-group">
                                <label for="lmstudio_endpoint">LM Studio Endpoint</label>
                                <input type="url" id="lmstudio_endpoint" name="lmstudio_endpoint" value="http://localhost:1234/v1/embeddings">
                                <small>LM Studio API endpoint</small>
                            </div>

                            <div class="form-group">
                                <label for="lmstudio_api_key">API Key (optional)</label>
                                <input type="password" id="lmstudio_api_key" name="lmstudio_api_key">
                                <small>Leave empty if LM Studio doesn't require authentication</small>
                            </div>
                        </div>
                        
                        <div class="alert alert-success" style="margin-top: 20px;">
                            <strong>🎉 Ready to Complete!</strong> Click "Complete Setup" to create your configuration file and first database.
                        </div>
                    </div>
                    

                    
                    <div class="wizard-nav">
                        <button type="button" class="btn btn-secondary" id="prevBtn" onclick="changeStep(-1)" style="display: none;">Previous</button>
                        <div>
                            <span id="stepIndicator">Step 1 of 4</span>
                        </div>
                        <button type="button" class="btn" id="nextBtn" onclick="changeStep(1)">Next</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            let currentStep = 1;
            const totalSteps = 4;
            
            function changeStep(direction) {
                console.log('changeStep called with direction:', direction, 'currentStep:', currentStep);
                
                // If we're on the final step and trying to go forward, submit the form instead
                if (currentStep === totalSteps && direction === 1) {
                    console.log('On final step, attempting to submit form');
                    if (validateCurrentStep()) {
                        console.log('Final validation passed, submitting form');
                        document.getElementById('wizardForm').submit();
                    } else {
                        console.log('Final validation failed');
                    }
                    return;
                }
                
                if (direction === 1 && !validateCurrentStep()) {
                    console.log('Validation failed for step:', currentStep);
                    return;
                }
                
                const newStep = currentStep + direction;
                console.log('newStep would be:', newStep, 'totalSteps:', totalSteps);
                
                if (newStep < 1 || newStep > totalSteps) {
                    console.log('newStep out of bounds, returning');
                    return;
                }
                
                // Hide current step
                document.getElementById('step' + currentStep).classList.remove('active');
                
                // Show new step
                currentStep = newStep;
                console.log('Moving to step:', currentStep);
                document.getElementById('step' + currentStep).classList.add('active');
                
                // Update progress
                const progress = (currentStep / totalSteps) * 100;
                document.getElementById('progressFill').style.width = progress + '%';
                
                // Update navigation
                document.getElementById('prevBtn').style.display = currentStep === 1 ? 'none' : 'inline-block';
                document.getElementById('nextBtn').textContent = currentStep === totalSteps ? 'Complete Setup' : 'Next';
                document.getElementById('stepIndicator').textContent = 'Step ' + currentStep + ' of ' + totalSteps;
                
                console.log('Button text set to:', document.getElementById('nextBtn').textContent);
                
                // Handle final step - keep button as 'button' type for better control
                if (currentStep === totalSteps) {
                    // Button stays as 'button' type, onclick handler remains changeStep(1)
                    // but the early return above handles the submission
                } else {
                    // Ensure onclick handler is set for non-final steps
                    document.getElementById('nextBtn').onclick = function() { changeStep(1); };
                }
            }
            
            function validateCurrentStep() {
                switch (currentStep) {
                    case 1:
                        const password = document.getElementById('password').value;
                        const passwordConfirm = document.getElementById('password_confirm').value;
                        
                        if (password.length < 6) {
                            alert('Password must be at least 6 characters long');
                            return false;
                        }
                        
                        if (password !== passwordConfirm) {
                            alert('Passwords do not match');
                            return false;
                        }
                        break;
                        
                    case 2:
                        const dbName = document.getElementById('database_name').value;
                        if (!dbName.trim()) {
                            alert('Database name is required');
                            return false;
                        }
                        if (!/^[a-zA-Z0-9_-]+$/.test(dbName)) {
                            alert('Database name can only contain letters, numbers, hyphens, and underscores');
                            return false;
                        }
                        break;
                        
                    case 3:
                        const chunkSize = parseInt(document.getElementById('chunk_size').value);
                        const chunkOverlap = parseInt(document.getElementById('chunk_overlap').value);
                        
                        if (chunkSize < 100 || chunkSize > 5000) {
                            alert('Chunk size must be between 100 and 5000 characters');
                            return false;
                        }
                        
                        if (chunkOverlap < 0 || chunkOverlap > 1000) {
                            alert('Chunk overlap must be between 0 and 1000 characters');
                            return false;
                        }
                        
                        if (chunkOverlap >= chunkSize) {
                            alert('Chunk overlap must be less than chunk size');
                            return false;
                        }
                        break;
                        
                    case 4:
                        const provider = document.getElementById('default_provider').value;
                        console.log('Step 4 validation - provider:', provider);
                        
                        if (provider === 'openai') {
                            const apiKey = document.getElementById('openai_api_key').value;
                            console.log('OpenAI API key:', apiKey ? 'provided' : 'empty', 'starts with sk-:', apiKey.startsWith('sk-'));
                            
                            if (!apiKey.startsWith('sk-')) {
                                const proceed = window.confirm('OpenAI API key appears invalid. Continue anyway?');
                                console.log('User chose to proceed:', proceed);
                                if (!proceed) return false;
                            }
                        }
                        
                        console.log('Step 4 validation passed');
                        break;
                }
                return true;
            }
            
            function selectProvider(provider, element) {
                // Update visual selection
                document.querySelectorAll('.provider-option').forEach(el => el.classList.remove('selected'));
                if (element) {
                    element.classList.add('selected');
                }
                
                // Update hidden input
                document.getElementById('default_provider').value = provider;
                
                // Show/hide configuration sections
                document.querySelectorAll('.provider-config').forEach(el => el.style.display = 'none');
                const configElement = document.getElementById(provider + '_config');
                if (configElement) {
                    configElement.style.display = 'block';
                }
            }
            

            
            function applyThemePreview(theme) {
                if (theme === 'dark') {
                    document.body.classList.add('dark-theme');
                } else {
                    document.body.classList.remove('dark-theme');
                }
            }
            
            // Initialize first provider selection
            document.addEventListener('DOMContentLoaded', function() {
                const firstProvider = document.querySelector('.provider-option');
                if (firstProvider) {
                    selectProvider('openai', firstProvider);
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Check if setup wizard is needed
if (needsSetupWizard() && !isset($_GET['setup_complete'])) {
    displaySetupWizard();
}

// Initialize the application
scanDatabases();

// Authentication check
function isAuthenticated() {
    global $password, $cookie_name, $session_timeout;
    
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        if (time() - $_SESSION['last_activity'] < $session_timeout) {
            $_SESSION['last_activity'] = time();
            return true;
        } else {
            session_destroy();
        }
    }
    
    return false;
}

/**
 * Verify password against configured password
 */
function verifyPassword($input_password, $stored_password) {
    // Handle different password formats
    if (strpos($stored_password, 'md5:') === 0) {
        // MD5 hash format
        $hash = substr($stored_password, 4);
        return md5($input_password) === $hash;
    } elseif (strpos($stored_password, 'sha256:') === 0) {
        // SHA256 hash format
        $hash = substr($stored_password, 7);
        return hash('sha256', $input_password) === $hash;
    } elseif (strpos($stored_password, 'bcrypt:') === 0) {
        // Bcrypt hash format (most secure)
        $hash = substr($stored_password, 7);
        return password_verify($input_password, $hash);
    } else {
        // Plain text (not recommended for production)
        return $input_password === $stored_password;
    }
}

// Handle login
if (isset($_POST['login'])) {
    $input_password = $_POST['password'] ?? '';
    
    if (verifyPassword($input_password, $password)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Invalid password';
        
        // Add a small delay to prevent brute force attacks
        sleep(1);
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check authentication (only for non-AJAX requests)
if (!isset($_GET['action']) && !isset($_POST['action']) && !isAuthenticated()) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>VectorLiteAdmin - Login</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 50px; }
            .login-container { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .logo { text-align: center; margin-bottom: 30px; }
            .logo h1 { color: #007cba; margin: 0; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 5px; font-weight: bold; }
            input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            .btn { background: #007cba; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
            .btn:hover { background: #005a8b; }
            .error { color: #d32f2f; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="logo">
                <h1>VectorLiteAdmin</h1>
                <p>Vector Database Management</p>
            </div>
            <form method="post">
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required autofocus>
                </div>
                <button type="submit" name="login" class="btn">Login</button>
                <?php if (isset($login_error)): ?>
                    <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle direct POST requests (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle password change only
    if (isset($_POST['change_password_only'])) {
        header('Content-Type: application/json');
        
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['password_confirm'] ?? '';
        $hash_type = $_POST['password_hash'] ?? 'sha256';
        
        // Validate current password
        if (!verifyPassword($current_password, $password)) {
            echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
            exit;
        }
        
        // Validate new password confirmation
        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
            exit;
        }
        
        // Validate new password length
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters long']);
            exit;
        }
        
        // Update password in config
        $new_values = [
            'password' => $new_password,
            'password_hash' => $hash_type
        ];
        
        if (updateConfigFile($new_values)) {
            echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update configuration file']);
        }
        exit;
    }
    
    // Handle group save
    if (isset($_POST['save_group'])) {
        header('Content-Type: application/json');
        
        $current_db = $_GET['db'] ?? null;
        if ($current_db && file_exists($current_db)) {
            connectDatabase($current_db);
        }
        
        if (!$pdo) {
            echo json_encode(['success' => false, 'error' => 'No database connection']);
            exit;
        }
        
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#007cba');
        
        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Name required']);
            exit;
        }
        
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE content_groups SET name = ?, description = ?, color = ? WHERE id = ?");
                $stmt->execute([$name, $desc, $color, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO content_groups (name, description, color) VALUES (?, ?, ?)");
                $stmt->execute([$name, $desc, $color]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle group delete
    if (isset($_POST['delete_group'])) {
        header('Content-Type: application/json');
        
        $current_db = $_GET['db'] ?? null;
        if ($current_db && file_exists($current_db)) {
            connectDatabase($current_db);
        }
        
        if (!$pdo) {
            echo json_encode(['success' => false, 'error' => 'No database connection']);
            exit;
        }
        
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Invalid group id']);
            exit;
        }
        
        try {
            // Prevent deleting default
            $stmt = $pdo->prepare("SELECT name FROM content_groups WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['name'] === 'default') {
                echo json_encode(['success' => false, 'error' => 'Cannot delete default group']);
                exit;
            }
            
            // Get group name before deletion
            $stmt = $pdo->prepare("SELECT name FROM content_groups WHERE id = ?");
            $stmt->execute([$id]);
            $group_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $group_name = $group_row['name'];

            // Delete the group (CASCADE will delete document_groups entries)
            $stmt = $pdo->prepare("DELETE FROM content_groups WHERE id = ?");
            $stmt->execute([$id]);

            // Ensure all documents have at least one group (add to default if they don't)
            $stmt = $pdo->prepare("
                INSERT OR IGNORE INTO document_groups (document_id, group_id)
                SELECT d.id, (SELECT id FROM content_groups WHERE name = 'default')
                FROM documents d
                WHERE NOT EXISTS (
                    SELECT 1 FROM document_groups dg WHERE dg.document_id = d.id
                )
            ");
            $stmt->execute();

            // For backward compatibility, update documents table group_name
            $stmt = $pdo->prepare("UPDATE documents SET group_name = 'default' WHERE group_name = ?");
            $stmt->execute([$group_name]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle AJAX requests
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Check authentication for AJAX requests
    if (!isAuthenticated()) {
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'];

    switch ($action) {
        case 'check_duplicates':
            $current_db = $_POST['db'] ?? $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            $filenames = $_POST['filenames'] ?? [];
            
            // Handle JSON string input
            if (is_string($filenames)) {
                $filenames = json_decode($filenames, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'error' => 'Invalid filenames format']);
                    break;
                }
            }
            
            if (!is_array($filenames)) {
                $filenames = [$filenames];
            }

            $duplicates = [];
            foreach ($filenames as $filename) {
                $file_title = pathinfo($filename, PATHINFO_FILENAME);
                $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                // Check for existing document with same title AND file type (extension)
                $stmt = $pdo->prepare("SELECT id, title, file_type FROM documents WHERE title = ? AND file_type = ?");
                $stmt->execute([$file_title, $file_extension]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $duplicates[] = [
                        'filename' => $filename,
                        'existing_id' => $existing['id'],
                        'existing_title' => $existing['title'],
                        'existing_type' => $existing['file_type']
                    ];
                }
            }

            echo json_encode(['success' => true, 'duplicates' => $duplicates]);
            break;

        case 'upload':
            $current_db = $_POST['db'] ?? $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            if (isset($_FILES['files'])) {
                $groups = $_POST['groups'] ?? ['default'];
                if (!is_array($groups)) {
                    $groups = [$groups];
                }
                // Ensure at least one group is selected
                if (empty($groups)) {
                    $groups = ['default'];
                }

                $duplicates = null;
                $replace_duplicates = isset($_POST['replace_duplicates']) && $_POST['replace_duplicates'] === '1';

                if ($replace_duplicates && isset($_POST['duplicates'])) {
                    $duplicates = json_decode($_POST['duplicates'], true);
                }

                try {
                    $results = uploadFiles($_FILES['files'], $groups, $duplicates);
                    echo json_encode(['success' => true, 'results' => $results]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Upload failed: ' . $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'No files uploaded']);
            }
            break;
            
        case 'process_embeddings':
            $results = processEmbeddingQueue();
            echo json_encode(['success' => true, 'results' => $results]);
            break;
            
        case 'search':
            // Connect to database if not already connected
            $current_db = $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            $query = $_POST['query'] ?? '';
            $options = [
                'limit' => $_POST['limit'] ?? 10,
                'threshold' => $_POST['threshold'] ?? 0.6,
                'group' => $_POST['group'] ?? null
            ];

            try {
                $results = vectorSearch($query, $options);
                echo json_encode(['success' => true, 'results' => $results]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'delete_document':
            $current_db = $_POST['db'] ?? $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            $document_id = $_POST['document_id'] ?? 0;
            $success = deleteDocument($document_id);
            echo json_encode(['success' => $success]);
            break;

        case 'update_document_groups':
            $current_db = $_POST['db'] ?? $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            $document_id = $_POST['document_id'] ?? 0;
            $group_ids = $_POST['group_ids'] ?? [];

            if (!is_array($group_ids)) {
                $group_ids = [$group_ids];
            }

            // Ensure at least one group is selected
            if (empty($group_ids)) {
                $group_ids = ['default'];
            }

            // Validate document exists
            if ($document_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid document ID']);
                break;
            }

            $stmt = $pdo->prepare("SELECT id FROM documents WHERE id = ?");
            $stmt->execute([$document_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Document not found']);
                break;
            }

            try {

                // Convert group names to IDs if needed
                $processed_group_ids = [];
                foreach ($group_ids as $group_id) {
                    if (is_numeric($group_id)) {
                        $processed_group_ids[] = $group_id;
                    } else {
                        // Group name provided - convert to ID
                        $stmt = $pdo->prepare("SELECT id FROM content_groups WHERE name = ?");
                        $stmt->execute([$group_id]);
                        $group_row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($group_row) {
                            $processed_group_ids[] = $group_row['id'];
                        }
                    }
                }

                // Remove existing group associations
                $stmt = $pdo->prepare("DELETE FROM document_groups WHERE document_id = ?");
                $stmt->execute([$document_id]);

                // Add new group associations
                $stmt = $pdo->prepare("INSERT INTO document_groups (document_id, group_id) VALUES (?, ?)");
                foreach ($processed_group_ids as $group_id) {
                    $stmt->execute([$document_id, $group_id]);
                }

                // Update documents table group_name for backward compatibility (use first group)
                $first_group_name = 'default';
                if (!empty($processed_group_ids)) {
                    $stmt = $pdo->prepare("SELECT name FROM content_groups WHERE id = ?");
                    $stmt->execute([$processed_group_ids[0]]);
                    $group_row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($group_row) {
                        $first_group_name = $group_row['name'];
                    }
                }

                $stmt = $pdo->prepare("UPDATE documents SET group_name = ? WHERE id = ?");
                $stmt->execute([$first_group_name, $document_id]);

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_document_groups':
            $current_db = $_GET['db'] ?? null;
            if ($current_db && file_exists($current_db)) {
                connectDatabase($current_db);
            }

            if (!$pdo) {
                echo json_encode(['success' => false, 'error' => 'No database connection']);
                break;
            }

            $document_id = $_GET['document_id'] ?? 0;
            try {
                // Get all available groups
                $all_groups = getContentGroups();

                // Get current groups for this document
                $stmt = $pdo->prepare("
                    SELECT dg.*, g.name, g.description, g.color
                    FROM document_groups dg
                    JOIN content_groups g ON dg.group_id = g.id
                    WHERE dg.document_id = ?
                    ORDER BY g.name
                ");
                $stmt->execute([$document_id]);
                $document_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'all_groups' => $all_groups,
                    'document_groups' => $document_groups
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_stats':
            if (isset($_GET['db']) && connectDatabase($_GET['db'])) {
                $stats = getDatabaseStats();
                echo json_encode(['success' => true, 'stats' => $stats]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            }
            break;
            
        case 'test_embedding':
            $test_text = $_POST['test_text'] ?? 'Test embedding';
            $test_provider = $_POST['provider'] ?? $default_provider;
            try {
                $result = generateEmbedding($test_text, $test_provider);
                echo json_encode([
                    'success' => true,
                    'provider' => $test_provider,
                    'dimensions' => count($result['data']),
                    'model' => $result['model']
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'update_settings':
            $result = handleSettingsUpdate();
            if ($result) {
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'error' => 'Settings update failed']);
            }
            break;

        case 'download_config':
            $config_path = __DIR__ . '/vectorliteadmin.config.php';
            if (file_exists($config_path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="vectorliteadmin.config.php"');
                header('Content-Length: ' . filesize($config_path));
                readfile($config_path);
            } else {
                echo 'Configuration file not found.';
            }
            break;
            
        case 'create_database':
            $db_name = $_POST['database_name'] ?? '';
            $db_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $db_name); // Sanitize
            
            if (empty($db_name)) {
                echo json_encode(['success' => false, 'error' => 'Database name is required']);
                break;
            }
            
            if (strlen($db_name) > 50) {
                echo json_encode(['success' => false, 'error' => 'Database name too long']);
                break;
            }
            
            $db_path = $directory . '/' . $db_name . '.sqlite';
            
            if (file_exists($db_path)) {
                echo json_encode(['success' => false, 'error' => 'Database already exists']);
                break;
            }
            
            if (createEmptyDatabase($db_path)) {
                echo json_encode(['success' => true, 'message' => 'Database created successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create database']);
            }
            break;
            
        case 'delete_database':
            $db_path_encoded = $_POST['database_path'] ?? '';
            
            if (empty($db_path_encoded)) {
                echo json_encode(['success' => false, 'error' => 'No database path provided']);
                break;
            }
            
            // Decode base64 encoded path
            $db_path = base64_decode($db_path_encoded);
            
            if (!file_exists($db_path)) {
                // Try to find the file in the configured directory
                $basename = basename($db_path);
                $alternative_path = $directory . DIRECTORY_SEPARATOR . $basename;
                
                if (file_exists($alternative_path)) {
                    $db_path = $alternative_path;
                } else {
                    echo json_encode(['success' => false, 'error' => 'Database file not found: ' . $db_path . ' (searched in: ' . $directory . ')']);
                    break;
                }
            }
            
            // Security check: ensure the path is within the configured directory
            $real_db_path = realpath($db_path);
            $real_directory = realpath($directory);
            
            if (!$real_db_path || !$real_directory) {
                echo json_encode(['success' => false, 'error' => 'Could not resolve database path']);
                break;
            }
            
            // Normalize paths for comparison
            $real_db_path = str_replace('\\', '/', $real_db_path);
            $real_directory = str_replace('\\', '/', $real_directory);
            
            if (strpos($real_db_path, $real_directory) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Database path is outside allowed directory']);
                break;
            }
            
            // Check if file is writable/deletable
            if (!is_writable($db_path)) {
                echo json_encode(['success' => false, 'error' => 'Database file is not writable']);
                break;
            }
            
            if (unlink($db_path)) {
                echo json_encode(['success' => true, 'message' => 'Database deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete database file']);
            }
            break;

        case 'process_selected_pending':
            // Ensure we have a DB connection
            if (!$pdo && isset($_GET['db'])) {
                connectDatabase($_GET['db']);
            }
            $ids = $_POST['document_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'error' => 'No documents selected']);
                break;
            }
            $limit = isset($_POST['limit']) ? intval($_POST['limit']) : null;
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
            $batch_index = isset($_POST['batch_index']) ? intval($_POST['batch_index']) : 0;

            try {
                $batch_result = processEmbeddingQueueForDocs(array_map('intval', $ids), $limit, $batch_size, $batch_index);
                echo json_encode([
                    'success' => true,
                    'results' => $batch_result['results'],
                    'total' => $batch_result['total'],
                    'completed' => $batch_result['completed'],
                    'batch_index' => $batch_result['batch_index'],
                    'has_more' => $batch_result['has_more'],
                    'progress_percent' => $batch_result['progress_percent']
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'save_group':
            if (!$pdo && isset($_GET['db'])) {
                connectDatabase($_GET['db']);
            }
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $color = trim($_POST['color'] ?? '#007cba');
            if ($name === '') { echo json_encode(['success' => false, 'error' => 'Name required']); break; }
            try {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE content_groups SET name = ?, description = ?, color = ? WHERE id = ?");
                    $stmt->execute([$name, $desc, $color, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO content_groups (name, description, color) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $desc, $color]);
                }
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'delete_group':
            if (!$pdo && isset($_GET['db'])) {
                connectDatabase($_GET['db']);
            }
            $id = intval($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Invalid group id']); break; }
            try {
                // Prevent deleting default
                $stmt = $pdo->prepare("SELECT name FROM content_groups WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['name'] === 'default') {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete default group']);
                    break;
                }
                $stmt = $pdo->prepare("DELETE FROM content_groups WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'change_password_only':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['password'] ?? '';
            $confirm_password = $_POST['password_confirm'] ?? '';
            $hash_type = $_POST['password_hash'] ?? 'sha256';
            
            // Validate current password
            if (!verifyPassword($current_password, $password)) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                break;
            }
            
            // Validate new password confirmation
            if ($new_password !== $confirm_password) {
                echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
                break;
            }
            
            // Validate new password length
            if (strlen($new_password) < 6) {
                echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters long']);
                break;
            }
            
            // Update password in config
            $new_values = [
                'password' => $new_password,
                'password_hash' => $hash_type
            ];
            
            if (updateConfigFile($new_values)) {
                echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update configuration file']);
            }
            break;

        case 'requeue_failed':
            if (!$pdo && isset($_GET['db'])) {
                connectDatabase($_GET['db']);
            }
            $all = isset($_POST['all']) && (($_POST['all'] === '1') || ($_POST['all'] === 'true'));
            try {
                if ($all) {
                    $stmt = $pdo->prepare("UPDATE embedding_queue SET status='pending', attempts=0, error_message=NULL WHERE status='failed'");
                    $stmt->execute();
                    echo json_encode(['success' => true, 'requeued' => $stmt->rowCount()]);
                } else {
                    $ids = $_POST['document_ids'] ?? [];
                    if (!is_array($ids) || empty($ids)) {
                        echo json_encode(['success' => false, 'error' => 'No documents selected']);
                        break;
                    }
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "UPDATE embedding_queue SET status='pending', attempts=0, error_message=NULL
                            WHERE status='failed' AND chunk_id IN (
                                SELECT c.id FROM chunks c WHERE c.document_id IN ($placeholders)
                            )";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_map('intval', $ids));
                    echo json_encode(['success' => true, 'requeued' => $stmt->rowCount()]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
    }
    exit;
}

// Connect to selected database
$current_db = $_GET['db'] ?? null;
$db_connected = false;
$stats = null;

if ($current_db && file_exists($current_db)) {
    $db_connected = connectDatabase($current_db);
    if ($db_connected) {
        $stats = getDatabaseStats();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>VectorLiteAdmin</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
        .header { background: #007cba; color: white; padding: 15px 20px; }
        .header h1 { margin: 0; display: inline-block; }
        .header .logout { float: right; color: white; text-decoration: none; padding: 5px 10px; background: rgba(255,255,255,0.2); border-radius: 3px; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        .database-selector { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .database-selector h2 { margin-top: 0; }
        .database-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; margin-top: 15px; }
        .database-item { 
            border: 1px solid #ddd; 
            padding: 15px; 
            border-radius: 8px; 
            transition: all 0.2s; 
            display: flex; 
            align-items: flex-start; 
            justify-content: space-between;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            min-height: 80px;
        }
        .database-item:hover { 
            border-color: #007cba; 
            background: #f8f9fa; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .database-item.active { 
            border-color: #007cba; 
            background: #e3f2fd; 
            box-shadow: 0 4px 12px rgba(0,124,186,0.2);
        }
        .database-item .database-info {
            flex: 1;
            cursor: pointer;
        }
        .database-item h3 { 
            margin: 0 0 8px 0; 
            color: #007cba; 
            font-size: 1.2em;
            font-weight: 600;
        }
        .database-item .meta { 
            font-size: 0.85em; 
            color: #666; 
            line-height: 1.4;
        }
        .database-item .meta-line {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .database-item .vector-badge { 
            background: #4caf50; 
            color: white; 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 0.75em;
            font-weight: 500;
        }
        .database-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-left: 15px;
            flex-shrink: 0;
        }
        
        .dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-card h3 { margin: 0 0 10px 0; color: #666; font-size: 0.9em; text-transform: uppercase; }
        .stat-card .number { font-size: 2em; font-weight: bold; color: #007cba; }
        
        .main-content { display: block; }
        .panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .panel h2 { margin-top: 0; color: #007cba; }
        
        .upload-area { border: 2px dashed #ddd; padding: 30px; text-align: center; border-radius: 8px; margin-bottom: 20px; transition: all 0.2s; }
        .upload-area.dragover { border-color: #007cba; background: #f0f8ff; }
        .upload-area input[type="file"] { display: none; }
        .upload-btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .upload-btn:hover { background: #005a8b; }
        .upload-instructions { color: #666; margin-bottom: 15px; }
        .upload-feedback { color: #dc3545; font-size: 0.9em; margin-bottom: 10px; font-weight: 500; }
        .file-list { list-style: none; padding: 0; margin-top: 0; margin-bottom: 15px; max-height: 200px; overflow-y: auto; text-align: left; }
        .file-list li { background: #f8f9fa; padding: 8px 35px 8px 12px; margin-bottom: 5px; border-radius: 4px; border-left: 3px solid #007cba; font-size: 0.9em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; position: relative; }
        .file-list li.over-limit { border-left-color: #dc3545; background: #fff5f5; }
        .file-list li .remove-file { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #666; cursor: pointer; font-size: 16px; padding: 2px 4px; border-radius: 2px; }
        .file-list li .remove-file:hover { color: #dc3545; background: rgba(220, 53, 69, 0.1); }
        .file-list li:last-child { margin-bottom: 0; }
        
        .search-form { margin-bottom: 20px; }
        .search-form input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px; }
        .search-options { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .search-options input, .search-options select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        
        .results { overflow: visible; }
        .result-item { border: 1px solid #eee; padding: 15px; margin-bottom: 10px; border-radius: 6px; }
        .result-item .similarity { float: right; background: #4caf50; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; }
        .result-item .source { color: #666; font-size: 0.9em; margin-bottom: 5px; }
        .result-item .content { line-height: 1.4; }
        
        .documents-list { overflow: visible; }
        .document-item { border: 1px solid #eee; padding: 15px; margin-bottom: 10px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
        .document-info h4 { margin: 0 0 5px 0; }
        .document-info .meta { color: #666; font-size: 0.9em; }
        .document-actions button { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px; }
        
        .progress-bar { width: 100%; height: 20px; background: #f0f0f0; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: #4caf50; transition: width 0.3s; }
        
        .btn { background: #007cba; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #005a8b; }
        .btn-success { background: #4caf50; }
        .btn-success:hover { background: #45a049; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Settings Panel Improvements */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { border-color: #007cba; outline: none; box-shadow: 0 0 0 2px rgba(0,124,186,0.2); }
        .form-group small { color: #666; font-size: 12px; margin-top: 4px; display: block; }
        .checkbox-group { display: flex; align-items: center; }
        .checkbox-group input[type="checkbox"] { width: auto; margin-right: 8px; }
        .checkbox-group label { margin-bottom: 0; }
        
        /* Popover Modal styling */
        [popover] {
            border: none;
            border-radius: 8px;
            max-width: 500px;
            width: 90vw;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            background: white;
            padding: 0;
            margin: auto;
        }
        [popover]::backdrop {
            background: rgba(0,0,0,0.5);
        }
        .modal-header { 
            padding: 20px 20px 0 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px solid #eee; 
            margin-bottom: 20px; 
        }
        .modal-header h3 { margin: 0; color: #007cba; }
        .modal-body { padding: 0 20px 20px 20px; }
        
        /* Fallback for browsers without popover support */
        .modal { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.5); 
            z-index: 1000; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .modal-content { 
            background: white; 
            border-radius: 8px; 
            max-width: 500px; 
            width: 90%; 
            max-height: 90vh; 
            overflow-y: auto; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3); 
        }
        
        /* Enhanced Search Styles */
        .search-input-container {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .search-input-container input {
            flex: 1;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
        }
        .search-input-container input:focus {
            border-color: #007cba;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,124,186,0.1);
        }
        .search-btn {
            padding: 12px 20px;
            font-size: 16px;
            white-space: nowrap;
        }
        .search-advanced {
            margin-top: 10px;
        }
        .search-advanced summary {
            cursor: pointer;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .search-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .option-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .option-group label {
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }
        #thresholdValue {
            font-weight: bold;
            color: #007cba;
        }
        
        /* Enhanced Document Styles */
        .document-filters {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .documents-list {
            overflow: visible;
        }
        .document-item {
            border: 1px solid #eee;
            border-radius: 8px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s;
            display: block;
            width: 100%;
        }
        .document-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .document-header {
            padding: 15px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
        }
        .document-header:hover {
            background: #f8f9fa;
        }
        .document-info {
            flex: 1;
        }
        .document-info h4 {
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-icon {
            font-size: 16px;
        }
        .expand-toggle {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 6px 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
        }
        .expand-toggle:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        .expand-icon {
            font-size: 14px;
            transition: transform 0.2s;
        }
        .document-item.expanded .expand-icon {
            transform: rotate(180deg);
        }
        .document-item.expanded .expand-toggle {
            background: #007cba;
            border-color: #007cba;
            color: white;
        }
        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .meta span {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 11px;
            color: #666;
        }
        .status-badge {
            font-weight: 500;
            color: white !important;
        }
        .status-embedded { background: #4caf50 !important; }
        .status-pending { background: #ff9800 !important; }
        .status-partial { background: #2196f3 !important; }
        .status-failed { background: #f44336 !important; }
        .document-content {
            padding: 0 15px 15px 15px;
            border-top: 1px solid #eee;
            background: #fafafa;
            width: 100%;
            clear: both;
        }
        .content-preview h5 {
            margin: 15px 0 10px 0;
            color: #007cba;
        }
        .content-text {
            padding: 10px;
            background: white;
            border-radius: 4px;
            border: 1px solid #eee;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 150px;
            overflow-y: auto;
        }
        .document-actions {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        
        /* Enhanced Group Styles */
        .groups-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .group-item {
            border: 1px solid #eee;
            border-radius: 8px;
            background: white;
            transition: all 0.2s;
        }
        .group-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .group-display {
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .group-info {
            flex: 1;
        }
        .group-name {
            font-size: 16px;
            color: #007cba;
            margin-bottom: 4px;
            display: block;
        }
        .group-description {
            color: #666;
            font-size: 14px;
        }
        .group-actions {
            display: flex;
            gap: 8px;
        }
        .group-edit {
            padding: 15px;
            border-top: 1px solid #eee;
            background: #f8f9fa;
        }
        .edit-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .edit-name, .edit-description {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            flex: 1;
            min-width: 150px;
        }
        .edit-actions {
            display: flex;
            gap: 5px;
        }
        
        /* Loading animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Highlight styles */
        mark {
            background: #ffeb3b !important;
            padding: 1px 2px;
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            .dashboard { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
            .search-options { grid-template-columns: 1fr; }
            .document-filters { flex-direction: column; align-items: stretch; }
            .document-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .edit-form { flex-direction: column; }
            .edit-name, .edit-description { min-width: auto; }
        }
    </style>
</head>
<body>
        <div class="header">
        <h1>VectorLiteAdmin</h1>
        <div style="float: right; display: flex; gap: 8px; align-items: center;">
            <?php if (!empty($db_connected) && $db_connected): ?>
                <a href="#" onclick="toggleDatabaseSelector()" style="color: white; text-decoration: none; padding: 5px 10px; background: rgba(255,255,255,0.2); border-radius: 3px;">🗄️ Databases</a>
            <?php endif; ?>
            <a href="#" onclick="toggleSettings()" style="color: white; text-decoration: none; padding: 5px 10px; background: rgba(255,255,255,0.2); border-radius: 3px;">⚙️ Settings</a>
            <a href="?logout=1" class="logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($_GET['setup_complete'])): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <strong>🎉 Setup Complete!</strong> VectorLiteAdmin has been successfully configured. 
                You can now upload documents and start building your vector database.
                <button onclick="this.parentElement.style.display='none'" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">&times;</button>
            </div>
        <?php endif; ?>
        
        <!-- Integrated Settings Panel -->
        <div id="settingsPanel" class="panel" style="display: none; margin-bottom: 20px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 1px solid #dee2e6;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #007cba;">
                <h2 style="margin: 0; color: #007cba;">⚙️ Settings</h2>
                <a href="#" onclick="returnFromSettingsToMain()" style="color: #007cba; text-decoration: none; padding: 8px 16px; background: white; border: 1px solid #007cba; border-radius: 4px; font-size: 14px;">← Back</a>
            </div>
            
            <?php 
            $settings_result = handleSettingsUpdate();
            if ($settings_result): 
            ?>
                <div class="alert <?php echo isset($settings_result['success']) ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($settings_result['success'] ?? $settings_result['error']); ?>
                </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px;">
                <!-- Current Configuration -->
                <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="color: #007cba; margin-top: 0;">Current Configuration</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                        <div style="margin-bottom: 8px;"><strong>Password:</strong> <?php echo $password === 'admin' ? '⚠️ Default (admin)' : '✅ Custom'; ?></div>
                        <div style="margin-bottom: 8px;"><strong>Database Directory:</strong> <?php echo htmlspecialchars($directory); ?></div>
                        <div style="margin-bottom: 8px;"><strong>Chunk Size:</strong> <?php echo $chunk_size; ?> chars (max)</div>
                        <div style="margin-bottom: 8px;"><strong>Default Provider:</strong> <?php echo ucfirst($default_provider); ?></div>
                        <div><strong>Config File:</strong> <?php echo file_exists(__DIR__ . '/vectorliteadmin.config.php') ? '✅ Exists' : '❌ Missing'; ?></div>
                    </div>
                    
                    <h3 style="color: #007cba;">System Information</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; font-size: 0.9em; margin-bottom: 20px;">
                        <div style="margin-bottom: 6px;"><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></div>
                        <div style="margin-bottom: 6px;"><strong>SQLite:</strong> <?php echo class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()) ? '✅ Available' : '❌ Missing'; ?></div>
                        <div style="margin-bottom: 6px;"><strong>cURL:</strong> <?php echo function_exists('curl_init') ? '✅ Available' : '❌ Missing'; ?></div>
                        <div style="margin-bottom: 6px;"><strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?></div>
                        <div><strong>Upload Max:</strong> <?php echo ini_get('upload_max_filesize'); ?></div>
                    </div>
                    
                    <div style="text-align: center;">
                        <button class="btn" onclick="downloadConfig()" style="background: #6c757d; color: white; padding: 10px 20px;">💾 Download Config</button>
                    </div>
                </div>
                
                <!-- Settings Form -->
                <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="color: #007cba; margin-top: 0;">Update Settings</h3>
                    <form method="post">
                        <input type="hidden" name="update_settings" value="1">
                        
                        <?php $current_config = loadExistingConfig(); ?>
                        
                        <!-- Security Settings -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #007cba;">
                            <h4 style="margin-top: 0; color: #007cba;">🔐 Security Settings</h4>
                            
                            <details style="margin-bottom: 15px;">
                                <summary style="cursor: pointer; font-weight: bold; color: #007cba; padding: 10px; background: white; border-radius: 4px; border: 1px solid #dee2e6;">Change Password</summary>
                                <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 4px; border: 1px solid #dee2e6;">
                                    <div class="form-group">
                                        <label for="settings_current_password">Current Password</label>
                                        <input type="password" id="settings_current_password" name="current_password" required>
                                        <small>Enter your current password to verify identity</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="settings_password">New Password</label>
                                        <input type="password" id="settings_password" name="password" minlength="6" required>
                                        <small>Enter new password (minimum 6 characters)</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="settings_password_confirm">Confirm New Password</label>
                                        <input type="password" id="settings_password_confirm" name="password_confirm" minlength="6" required>
                                        <small>Re-enter new password to confirm</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="settings_password_hash">Password Hash Type</label>
                                        <select id="settings_password_hash" name="password_hash">
                                            <option value="plain">Plain Text</option>
                                            <option value="md5">MD5 Hash</option>
                                            <option value="sha256" selected>SHA256 Hash</option>
                                            <option value="bcrypt">Bcrypt Hash</option>
                                        </select>
                                    </div>
                                    
                                    <div style="margin-top: 15px; text-align: center;">
                                        <button type="button" class="btn" onclick="changePassword()">🔐 Change Password</button>
                                    </div>
                                </div>
                            </details>
                        </div>
                        
                        <!-- Database & Storage Settings -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                            <h4 style="margin-top: 0; color: #28a745;">💾 Database & Storage</h4>
                            
                            <div class="form-group">
                                <label for="settings_directory">Database Directory</label>
                                <input type="text" id="settings_directory" name="database_directory" value="<?php echo htmlspecialchars($current_config['directory'] ?? $directory); ?>">
                            </div>
                            
                            <div class="form-group checkbox-group">
                                <input type="checkbox" id="settings_subdirectories" name="scan_subdirectories" <?php echo ($current_config['subdirectories'] ?? $subdirectories) ? 'checked' : ''; ?>>
                                <label for="settings_subdirectories">Scan subdirectories</label>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="settings_upload_size">Max Upload Size (MB)</label>
                                    <input type="number" id="settings_upload_size" name="max_upload_size" value="<?php echo $current_config['max_upload_size'] ?? $max_upload_size; ?>" min="1" max="500">
                                </div>
                                
                                <div class="form-group">
                                    <label for="settings_session_timeout">Session Timeout (seconds)</label>
                                    <input type="number" id="settings_session_timeout" name="session_timeout" value="<?php echo $current_config['session_timeout'] ?? $session_timeout; ?>" min="300">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Content Processing Settings -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #17a2b8;">
                            <h4 style="margin-top: 0; color: #17a2b8;">📄 Content Processing</h4>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="settings_chunk_size">Max Chunk Size</label>
                                    <input type="number" id="settings_chunk_size" name="chunk_size" value="<?php echo $current_config['chunk_size'] ?? $chunk_size; ?>" min="100" max="5000">
                                </div>
                                
                                <div class="form-group">
                                    <label for="settings_chunk_overlap">Chunk Overlap</label>
                                    <input type="number" id="settings_chunk_overlap" name="chunk_overlap" value="<?php echo $current_config['chunk_overlap'] ?? $chunk_overlap; ?>" min="0" max="1000">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Embedding Provider Settings -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #6f42c1;">
                            <h4 style="margin-top: 0; color: #6f42c1;">🤖 Embedding Provider</h4>
                            
                            <div class="form-group">
                                <label for="settings_provider">Embedding Provider</label>
                                <select id="settings_provider" name="default_provider" onchange="showProviderConfig(this.value)">
                                    <option value="openai" <?php echo ($current_config['default_provider'] ?? $default_provider) === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                                    <option value="gemini" <?php echo ($current_config['default_provider'] ?? $default_provider) === 'gemini' ? 'selected' : ''; ?>>Gemini</option>
                                    <option value="ollama" <?php echo ($current_config['default_provider'] ?? $default_provider) === 'ollama' ? 'selected' : ''; ?>>Ollama</option>
                                    <option value="lmstudio" <?php echo ($current_config['default_provider'] ?? $default_provider) === 'lmstudio' ? 'selected' : ''; ?>>LM Studio</option>
                                </select>
                            </div>

                            <!-- Provider-specific settings -->
                            <div id="settings_openai_config" class="provider-settings" style="<?php echo ($current_config['default_provider'] ?? $default_provider) !== 'openai' ? 'display: none;' : ''; ?>">
                                <div class="form-group">
                                    <label for="settings_openai_key">OpenAI API Key</label>
                                    <input type="password" id="settings_openai_key" name="openai_api_key" value="<?php echo htmlspecialchars($current_config['openai_api_key'] ?? ''); ?>" placeholder="sk-...">
                                </div>
                                <div class="form-group">
                                    <label for="settings_openai_model">OpenAI Model</label>
                                    <select id="settings_openai_model" name="openai_model">
                                        <option value="text-embedding-3-small" <?php echo ($current_config['openai_model'] ?? '') === 'text-embedding-3-small' ? 'selected' : ''; ?>>text-embedding-3-small</option>
                                        <option value="text-embedding-3-large" <?php echo ($current_config['openai_model'] ?? '') === 'text-embedding-3-large' ? 'selected' : ''; ?>>text-embedding-3-large</option>
                                        <option value="text-embedding-ada-002" <?php echo ($current_config['openai_model'] ?? '') === 'text-embedding-ada-002' ? 'selected' : ''; ?>>text-embedding-ada-002</option>
                                    </select>
                                </div>
                                <div style="margin-top: 15px;">
                                    <button type="button" class="btn" onclick="saveAndTestProvider('openai')" style="background: #6f42c1;">💾 Save & Test Provider</button>
                                </div>
                            </div>

                            <div id="settings_gemini_config" class="provider-settings" style="<?php echo ($current_config['default_provider'] ?? $default_provider) !== 'gemini' ? 'display: none;' : ''; ?>">
                                <div class="form-group">
                                    <label for="settings_gemini_key">Google AI API Key</label>
                                    <input type="password" id="settings_gemini_key" name="gemini_api_key" value="<?php echo htmlspecialchars($current_config['gemini_api_key'] ?? ''); ?>" placeholder="AIza...">
                                </div>
                                <div class="form-group">
                                    <label for="settings_gemini_model">Gemini Model</label>
                                    <select id="settings_gemini_model" name="gemini_model">
                                        <option value="gemini-embedding-001" <?php echo ($current_config['gemini_model'] ?? '') === 'gemini-embedding-001' ? 'selected' : ''; ?>>gemini-embedding-001</option>
                                    </select>
                                </div>
                                <div style="margin-top: 15px;">
                                    <button type="button" class="btn" onclick="saveAndTestProvider('gemini')" style="background: #6f42c1;">💾 Save & Test Provider</button>
                                </div>
                            </div>
                            
                            <div id="settings_ollama_config" class="provider-settings" style="<?php echo ($current_config['default_provider'] ?? $default_provider) !== 'ollama' ? 'display: none;' : ''; ?>">
                                <div class="form-group">
                                    <label for="settings_ollama_endpoint">Ollama Endpoint</label>
                                    <input type="url" id="settings_ollama_endpoint" name="ollama_endpoint" value="http://localhost:11434/api/embeddings">
                                </div>
                                <div class="form-group">
                                    <label for="settings_ollama_model">Ollama Model</label>
                                    <input type="text" id="settings_ollama_model" name="ollama_model" value="nomic-embed-text">
                                </div>
                                <div style="margin-top: 15px;">
                                    <button type="button" class="btn" onclick="saveAndTestProvider('ollama')" style="background: #6f42c1;">💾 Save & Test Provider</button>
                                </div>
                            </div>
                            
                            <div id="settings_lmstudio_config" class="provider-settings" style="<?php echo ($current_config['default_provider'] ?? $default_provider) !== 'lmstudio' ? 'display: none;' : ''; ?>">
                                <div class="form-group">
                                    <label for="settings_lmstudio_endpoint">LM Studio Endpoint</label>
                                    <input type="url" id="settings_lmstudio_endpoint" name="lmstudio_endpoint" value="http://localhost:1234/v1/embeddings">
                                </div>
                                <div class="form-group">
                                    <label for="settings_lmstudio_key">LM Studio API Key</label>
                                    <input type="password" id="settings_lmstudio_key" name="lmstudio_api_key" placeholder="Optional">
                                </div>
                                <div style="margin-top: 15px;">
                                    <button type="button" class="btn" onclick="saveAndTestProvider('lmstudio')" style="background: #6f42c1;">💾 Save & Test Provider</button>
                                </div>
                            </div>
                        </div>

                        <!-- Interface Settings -->
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #fd7e14;">
                            <h4 style="margin-top: 0; color: #fd7e14;">🎨 Interface</h4>
                            
                            <div class="form-group">
                                <label for="settings_theme">Theme</label>
                                <select id="settings_theme" name="theme">
                                    <option value="default" <?php echo ($current_config['theme'] ?? 'default') === 'default' ? 'selected' : ''; ?>>Default</option>
                                    <option value="dark" <?php echo ($current_config['theme'] ?? 'default') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                    <option value="light" <?php echo ($current_config['theme'] ?? 'default') === 'light' ? 'selected' : ''; ?>>Light</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Advanced Options (Hidden by default) -->
                        <details style="margin-bottom: 20px;">
                            <summary style="cursor: pointer; font-weight: bold; color: #666; padding: 10px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #dc3545;">🔧 Advanced Options</summary>
                            <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 4px; border: 1px solid #ffeaa7;">
                                <div class="form-group checkbox-group">
                                    <input type="checkbox" id="settings_debug" name="enable_debug" <?php echo ($current_config['enable_debug'] ?? false) ? 'checked' : ''; ?>>
                                    <label for="settings_debug">Enable Debug Mode</label>
                                    <small style="display: block; margin-left: 25px; color: #856404;">Show detailed error messages (not recommended for production)</small>
                                </div>
                                
                                <div class="form-group checkbox-group">
                                    <input type="checkbox" id="settings_log_queries" name="log_queries" <?php echo ($current_config['log_queries'] ?? false) ? 'checked' : ''; ?>>
                                    <label for="settings_log_queries">Log Database Queries</label>
                                    <small style="display: block; margin-left: 25px; color: #856404;">Log all database queries for debugging (may impact performance)</small>
                                </div>
                            </div>
                        </details>
                        
                        <div style="margin-top: 20px; text-align: center;">
                            <button type="submit" class="btn">💾 Update Settings</button>
                            <button type="button" class="btn btn-secondary" onclick="toggleSettings()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Database Selector -->
        <div class="database-selector" style="display: <?php echo ($db_connected ? 'none' : 'block'); ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Databases</h2>
                <button class="btn" popovertarget="createDatabaseModal" style="background: #28a745;">+ New Database</button>
            </div>
            
            <?php if (empty($databases)): ?>
                <div style="text-align: center; padding: 60px 20px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #dee2e6;">
                    <div style="font-size: 48px; margin-bottom: 20px;">🗄️</div>
                    <h3 style="color: #6c757d; margin-bottom: 15px;">No Databases Found</h3>
                    <p style="color: #6c757d; margin-bottom: 25px;">Create your first vector database to get started with document storage and semantic search.</p>
                    <button class="btn" popovertarget="createDatabaseModal" style="background: #28a745; font-size: 16px; padding: 12px 24px;">
                        🚀 Create Your First Database
                    </button>
                </div>
            <?php else: ?>
                <div class="database-list">
                    <?php foreach ($databases as $db): ?>
                        <div class="database-item <?php echo $current_db === $db['path'] ? 'active' : ''; ?>">
                            <div class="database-info" onclick="location.href='?db=<?php echo urlencode($db['path']); ?>'">
                                <h3><?php echo htmlspecialchars($db['name']); ?></h3>
                                <div class="meta">
                                    <div class="meta-line">
                                        <span>Size: <?php echo number_format($db['size'] / 1024, 1); ?> KB</span>
                                        <?php if ($db['is_vector_db']): ?>
                                            <span class="vector-badge">Vector DB</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="meta-line">
                                        <span>Modified: <?php echo date('Y-m-d H:i', $db['modified']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="database-actions">
                                <button onclick="showDeleteDatabaseModal('<?php echo htmlspecialchars($db['name']); ?>', '<?php echo base64_encode($db['path']); ?>')" 
                                        class="btn" style="background: #dc3545; color: white; padding: 6px 12px; font-size: 12px; border-radius: 4px;">
                                    🗑️ Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Create Database Modal -->
        <div id="createDatabaseModal" popover>
            <div class="modal-header">
                <h3>Create New Database</h3>
                <button popovertarget="createDatabaseModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form id="createDatabaseForm" onsubmit="createNewDatabase(event)">
                    <div class="form-group">
                        <label for="new_database_name">Database Name</label>
                        <input type="text" id="new_database_name" name="database_name" required 
                               pattern="[a-zA-Z0-9_\-]+" maxlength="50" placeholder="my-new-database">
                        <small>Letters, numbers, hyphens, and underscores only (max 50 characters)</small>
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" popovertarget="createDatabaseModal">Cancel</button>
                        <button type="submit" class="btn" style="background: #28a745;">Create Database</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Delete Database Modal -->
        <div id="deleteDatabaseModal" popover>
            <div class="modal-header">
                <h3>Delete Database</h3>
                <button popovertarget="deleteDatabaseModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    <strong>⚠️ Warning:</strong> This action cannot be undone. All documents, embeddings, and data in this database will be permanently deleted.
                </div>
                <form id="deleteDatabaseForm" onsubmit="deleteDatabase(event)">
                    <input type="hidden" id="delete_database_path" name="database_path">
                    
                    <div class="form-group">
                        <label>Database to delete:</label>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-weight: bold;" id="delete_database_display"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="delete_confirmation">Type "DELETE" to confirm:</label>
                        <input type="text" id="delete_confirmation" required placeholder="DELETE" style="text-transform: uppercase;">
                    </div>
                    
                    <div class="form-group">
                        <label for="delete_database_name_confirm">Type the database name to confirm:</label>
                        <input type="text" id="delete_database_name_confirm" required placeholder="Database name">
                    </div>
                    
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" popovertarget="deleteDatabaseModal">Cancel</button>
                        <button type="submit" class="btn" style="background: #dc3545;">Delete Database</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Upload Documents Modal -->
        <div id="uploadModal" popover>
            <div class="modal-header">
                <h3>📁 Upload Documents</h3>
                <button onclick="cancelUpload()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="upload-area" id="uploadArea">
                    <div id="uploadInstructions" class="upload-instructions">Drag and drop files here or click to select</div>
                    <div id="uploadFeedback" class="upload-feedback" style="display: none;"></div>
                    <ul id="fileList" class="file-list" style="display: none;"></ul>
                    <input type="file" id="fileInput" multiple accept=".txt,.md,.pdf,.doc,.docx,.rtf">
                    <button class="upload-btn" onclick="document.getElementById('fileInput').click()">Select Files</button>
                </div>
                
                <div class="form-group">
                    <label>Content Groups:</label>
                    <div class="group-checkboxes" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
                        <?php
                        if ($db_connected) {
                            $groups = getContentGroups();
                            foreach ($groups as $group):
                        ?>
                                <div class="checkbox-group" style="margin-bottom: 8px;">
                                    <label style="margin-bottom: 0; font-weight: normal; display: flex; align-items: center; cursor: pointer;">
                                        <input type="checkbox"
                                               name="groups[]"
                                               value="<?php echo htmlspecialchars($group['id']); ?>"
                                               <?php echo ($group['name'] === 'default') ? 'checked' : ''; ?>
                                               style="margin-right: 10px;">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                        <?php if ($group['description']): ?>
                                            <small style="margin: 0 2px; color: #666;"> - <?php echo htmlspecialchars($group['description']); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                        <?php
                            endforeach;
                        } else {
                        ?>
                            <div class="checkbox-group" style="margin-bottom: 8px;">
                                <label style="margin-bottom: 0; font-weight: normal; display: flex; align-items: center; cursor: pointer;">
                                    <input type="checkbox" name="groups[]" value="default" checked style="margin-right: 10px;">
                                    Default
                                </label>
                            </div>
                        <?php
                        }
                        ?>
                    </div>
                    <small style="color: #666;">Select one or more groups for the uploaded documents</small>
                </div>
                
                <div id="uploadProgress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div id="uploadStatus"></div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="cancelUpload()">Cancel</button>
                    <button type="button" class="btn" onclick="startUpload()">📤 Upload Files</button>
                </div>
            </div>
        </div>

        <!-- Duplicate Files Modal -->
        <div id="duplicateModal" popover>
            <div class="modal-header">
                <h3>⚠️ Duplicate Files Detected</h3>
                <button onclick="closeDuplicateModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <p>The following files already exist in the database:</p>
                <div id="duplicateList" style="max-height: 200px; overflow-y: auto; margin: 15px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"></div>
                <p>What would you like to do with these duplicate files?</p>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="handleDuplicates('ignore')">Ignore Duplicates</button>
                    <button type="button" class="btn" onclick="handleDuplicates('replace')">Replace Existing</button>
                </div>
            </div>
        </div>

        <!-- Edit Document Groups Modal -->
        <div id="editDocumentGroupsModal" popover>
            <div class="modal-header">
                <h3>📁 Edit Document Groups</h3>
                <button popovertarget="editDocumentGroupsModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div id="editGroupsDocumentTitle" style="margin-bottom: 20px; font-weight: bold;"></div>

                <form id="editGroupsForm" onsubmit="updateDocumentGroups(event)">
                    <input type="hidden" id="edit_groups_document_id" name="document_id">

                    <div class="form-group">
                        <label>Select Groups:</label>
                        <div class="group-checkboxes" id="editGroupsCheckboxes" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
                            <!-- Groups will be loaded here -->
                        </div>
                        <small style="color: #666;">Select one or more groups for this document</small>
                    </div>

                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" popovertarget="editDocumentGroupsModal">Cancel</button>
                        <button type="submit" class="btn">💾 Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create Group Modal -->
        <div id="createGroupModal" popover>
            <div class="modal-header">
                <h3>➕ Create New Group</h3>
                <button popovertarget="createGroupModal" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form id="createGroupForm" onsubmit="createGroup(event)">
                    <div class="form-group">
                        <label for="new_group_name">Group Name</label>
                        <input type="text" id="new_group_name" name="name" required placeholder="Enter group name">
                    </div>
                    <div class="form-group">
                        <label for="new_group_description">Description</label>
                        <input type="text" id="new_group_description" name="description" placeholder="Optional description">
                    </div>
                    
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" popovertarget="createGroupModal">Cancel</button>
                        <button type="submit" class="btn">Create Group</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($db_connected && $stats): ?>
            <!-- Dashboard Stats -->
            <div class="dashboard">
                <div class="stat-card">
                    <h3>Documents</h3>
                    <div class="number"><?php echo $stats['documents']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Chunks</h3>
                    <div class="number"><?php echo $stats['chunks']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Embeddings</h3>
                    <div class="number"><?php echo $stats['embeddings']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending</h3>
                    <div class="number"><?php echo $stats['pending_embeddings']; ?></div>
                </div>
            </div>
            
            <!-- Vector Search Panel (Full Width) -->
            <div class="panel" style="margin-bottom: 20px;">
                <h2>🔍 Vector Search</h2>
                <div class="search-form">
                    <div class="search-input-container">
                        <input type="text" id="searchQuery" placeholder="What are you looking for? (e.g., 'machine learning algorithms', 'user authentication'...)">
                        <button class="btn search-btn" onclick="performSearch()">🔍 Search</button>
                    </div>
                    
                    <details class="search-advanced">
                        <summary>Advanced Options</summary>
                        <div class="search-options">
                            <div class="option-group">
                                <label for="searchLimit">Results Limit:</label>
                                <input type="number" id="searchLimit" value="10" min="1" max="50">
                            </div>
                            <div class="option-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                <label for="searchThreshold">Similarity Threshold:</label>
                                <span id="thresholdValue">60%</span>
                                </div>
                                <input type="range" id="searchThreshold" value="0.6" min="0" max="1" step="0.05" title="Minimum similarity required for results to appear (higher = fewer, more relevant results)">

                            </div>
                            <div class="option-group">
                                <label for="searchGroup">Filter by Group:</label>
                                <select id="searchGroup">
                                    <option value="">All Groups</option>
                                    <?php 
                                    if ($db_connected) {
                                        $groups = getContentGroups();
                                        foreach ($groups as $group): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($group['name']); ?>">
                                            <?php echo htmlspecialchars($group['name']); ?>
                                        </option>
                                    <?php 
                                        endforeach; 
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </details>
                </div>
                
                <div id="searchResults" class="results"></div>
            </div>
            
            <!-- Documents Panel -->
            <div id="documentsPanel" class="panel" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <h2 style="margin: 0;">📄 Documents</h2>
                    
                    <!-- Upload Button -->
                    <button class="btn" onclick="showUploadModal()" style="background: #28a745;">📁 Upload Documents</button>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                    <!-- Filters -->
                    <div class="document-filters" style="display:flex; gap:8px; align-items:center;">
                        <?php if ($current_db): ?>
                            <input type="hidden" id="current_db" value="<?php echo htmlspecialchars($current_db); ?>">
                        <?php endif; ?>
                        
                        <label for="filter_group" style="font-size: 14px; color:#555;">Group:</label>
                        <select id="filter_group" onchange="filterDocuments()">
                            <option value="">All Groups</option>
                            <?php if ($db_connected) { $all_groups = getContentGroups(); foreach ($all_groups as $gopt): ?>
                                <option value="<?php echo htmlspecialchars($gopt['name']); ?>" <?php echo (($_GET['doc_group'] ?? '') === $gopt['name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gopt['name']); ?>
                                </option>
                            <?php endforeach; } ?>
                        </select>
                        
                        <label for="filter_status" style="font-size: 14px; color:#555;">Status:</label>
                        <select id="filter_status" onchange="filterDocuments()">
                            <option value="">All Status</option>
                            <option value="embedded" <?php echo (($_GET['doc_status'] ?? '') === 'embedded') ? 'selected' : ''; ?>>✅ Fully Embedded</option>
                            <option value="pending" <?php echo (($_GET['doc_status'] ?? '') === 'pending') ? 'selected' : ''; ?>>⏳ Pending</option>
                            <option value="partial" <?php echo (($_GET['doc_status'] ?? '') === 'partial') ? 'selected' : ''; ?>>🔄 Partially Embedded</option>
                            <option value="failed" <?php echo (($_GET['doc_status'] ?? '') === 'failed') ? 'selected' : ''; ?>>❌ Failed</option>
                        </select>
                        
                        <label for="sort_by" style="font-size: 14px; color:#555;">Sort:</label>
                        <select id="sort_by" onchange="filterDocuments()">
                            <option value="created_desc" <?php echo (($_GET['doc_sort'] ?? 'created_desc') === 'created_desc') ? 'selected' : ''; ?>>📅 Newest First</option>
                            <option value="created_asc" <?php echo (($_GET['doc_sort'] ?? '') === 'created_asc') ? 'selected' : ''; ?>>📅 Oldest First</option>
                            <option value="title_asc" <?php echo (($_GET['doc_sort'] ?? '') === 'title_asc') ? 'selected' : ''; ?>>🔤 Title A-Z</option>
                            <option value="title_desc" <?php echo (($_GET['doc_sort'] ?? '') === 'title_desc') ? 'selected' : ''; ?>>🔤 Title Z-A</option>
                            <option value="type_asc" <?php echo (($_GET['doc_sort'] ?? '') === 'type_asc') ? 'selected' : ''; ?>>📁 Type A-Z</option>
                            <option value="size_desc" <?php echo (($_GET['doc_sort'] ?? '') === 'size_desc') ? 'selected' : ''; ?>>📏 Size Large-Small</option>
                            <option value="size_asc" <?php echo (($_GET['doc_sort'] ?? '') === 'size_asc') ? 'selected' : ''; ?>>📏 Size Small-Large</option>
                        </select>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="display:flex; gap:8px; flex-wrap: wrap;">
                        <button class="btn" id="processSelectedBtn" onclick="processSelectedPending()">⚡ Process Selected Pending</button>
                        <button class="btn btn-secondary" onclick="requeueAllFailed()">🔄 Re-queue All Failed</button>
                        <button class="btn btn-secondary" onclick="selectAllPending(true)">☑️ Select All Pending</button>
                        <button class="btn btn-secondary" onclick="selectAllPending(false)">☐ Clear Selection</button>
                    </div>
                </div>
                <!-- Processing Progress Area -->
                <div id="processProgressArea" style="display: none; justify-content: center; align-items: center; margin: -5px 0 20px 0;">
                    <div id="processStatus" style="flex: 1; font-size: 0.9em; color: #666;"></div>
                    <div class="progress-bar" style="flex: 1;">
                        <div class="progress-fill" id="processProgressFill" style="width: 0%"></div>
                    </div>
                </div>
                <?php
                // Pagination + filtering logic
                $doc_group = $_GET['doc_group'] ?? '';
                $doc_page = max(1, (int)($_GET['doc_page'] ?? 1));
                $doc_per_page = 20;
                $total_docs = $db_connected ? countDocuments($doc_group ?: null) : 0;
                $total_pages = $doc_per_page > 0 ? max(1, (int)ceil($total_docs / $doc_per_page)) : 1;
                if ($doc_page > $total_pages) $doc_page = $total_pages;
                ?>
                <div id="documentsList" class="documents-list">
                    <?php
                    if ($db_connected) {
                        $documents = getDocuments($doc_page, $doc_per_page, $doc_group ?: null);
                        foreach ($documents as $doc):
                            $chunks = (int)$doc['chunk_count'];
                            $embedded = (int)($doc['embedding_count'] ?? 0);
                            $pending = (int)($doc['pending_count'] ?? 0);
                            $failed = $chunks - $embedded - $pending;
                            
                            // Determine status
                            $status = 'embedded';
                            $status_icon = '✅';
                            $status_text = 'Fully Embedded';
                            
                            if ($pending > 0) {
                                $status = 'pending';
                                $status_icon = '⏳';
                                $status_text = 'Pending';
                            } elseif ($failed > 0) {
                                $status = 'failed';
                                $status_icon = '❌';
                                $status_text = 'Failed';
                            } elseif ($embedded > 0 && $embedded < $chunks) {
                                $status = 'partial';
                                $status_icon = '🔄';
                                $status_text = 'Partially Embedded';
                            }
                    ?>
                        <div class="document-item" data-group="<?php
                            $doc_groups = getDocumentGroups($doc['id']);
                            if (empty($doc_groups)) {
                                echo htmlspecialchars($doc['group_name']); // fallback for backward compatibility
                            } else {
                                $group_names = array_map(function($g) { return $g['name']; }, $doc_groups);
                                echo htmlspecialchars(implode(',', $group_names));
                            }
                        ?>" data-status="<?php echo $status; ?>" data-type="<?php echo htmlspecialchars($doc['file_type']); ?>" data-size="<?php echo $doc['file_size']; ?>" data-title="<?php echo htmlspecialchars($doc['title']); ?>" data-created="<?php echo $doc['created_at']; ?>">
                            <div class="document-header" onclick="toggleDocumentContent(this)">
                                <div class="document-info">
                                    <h4 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                                        <?php if ($pending > 0): ?>
                                            <input type="checkbox" class="doc-select" value="<?php echo $doc['id']; ?>" title="Select for pending processing" onclick="event.stopPropagation()">
                                        <?php endif; ?>
                                        <span class="status-icon"><?php echo $status_icon; ?></span>
                                        <?php echo htmlspecialchars($doc['title']); ?>
                                    </h4>
                                    <div class="meta" style="margin-top: 4px; font-size: 12px; color: #666;">
                                        <span class="status-badge status-<?php echo $status; ?>"><?php echo $status_text; ?></span>
                                        <span>📁 <?php
                                            $doc_groups = getDocumentGroups($doc['id']);
                                            if (empty($doc_groups)) {
                                                echo htmlspecialchars($doc['group_name']); // fallback for backward compatibility
                                            } else {
                                                $group_names = array_map(function($g) { return $g['name']; }, $doc_groups);
                                                echo htmlspecialchars(implode(', ', $group_names));
                                            }
                                        ?></span>
                                        <span>🧩 <?php echo $chunks; ?> chunks</span>
                                        <span>✅ <?php echo $embedded; ?> embedded</span>
                                        <?php if ($pending > 0): ?><span>⏳ <?php echo $pending; ?> pending</span><?php endif; ?>
                                        <?php if ($failed > 0): ?><span>❌ <?php echo $failed; ?> failed</span><?php endif; ?>
                                        <span>📄 <?php echo strtoupper($doc['file_type']); ?></span>
                                        <span>📏 <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB</span>
                                        <span>📅 <?php echo date('M j, Y', strtotime($doc['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="document-actions" onclick="event.stopPropagation()" style="display: flex; align-items: center; gap: 8px;">
                                    <button class="expand-toggle" onclick="event.stopPropagation(); toggleDocumentContent(this.closest('.document-header'))" title="Show/Hide Content">
                                        <span class="expand-icon">📄</span>
                                    </button>
                                    <button class="btn btn-secondary" onclick="editDocumentGroups(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['title']); ?>')" title="Edit Groups">📁 Edit Groups</button>
                                    <button class="btn btn-danger" onclick="deleteDocument(<?php echo $doc['id']; ?>)">🗑️ Delete</button>
                                </div>
                            </div>
                            <div class="document-content" style="display: none;">
                                <div class="content-preview">
                                    <h5>Document Content:</h5>
                                    <div class="content-text"><?php echo nl2br(htmlspecialchars($doc['content'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php 
                        endforeach; 
                    }
                    ?>
                </div>
                <?php if ($db_connected && $total_pages > 1):
                    // build base URL with db and doc_group
                    $base = '?';
                    $params = [];
                    if ($current_db) $params['db'] = $current_db;
                    if ($doc_group !== '') $params['doc_group'] = $doc_group;
                    $build = function($page) use ($params) { $params2 = $params; $params2['doc_page'] = $page; return htmlspecialchars('?' . http_build_query($params2)); };
                ?>
                <div style="display:flex; justify-content:center; gap:6px; margin-top: 12px;">
                    <?php if ($doc_page > 1): ?>
                        <a class="btn btn-secondary" href="<?php echo $build($doc_page - 1); ?>">Prev</a>
                    <?php endif; ?>
                    <span style="align-self:center; color:#555;">Page <?php echo $doc_page; ?> of <?php echo $total_pages; ?></span>
                    <?php if ($doc_page < $total_pages): ?>
                        <a class="btn btn-secondary" href="<?php echo $build($doc_page + 1); ?>">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Groups Management Panel -->
            <div class="panel" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>🏷️ Groups</h2>
                    <button class="btn" onclick="showCreateGroupModal()">➕ Create Group</button>
                </div>
                <?php if ($db_connected): $groups = getContentGroups(); ?>
                    <div class="groups-list">
                        <?php foreach ($groups as $g): ?>
                            <div class="group-item" id="group-<?php echo $g['id']; ?>">
                                <div class="group-display">
                                    <div class="group-info">
                                        <strong class="group-name"><?php echo htmlspecialchars($g['name']); ?></strong>
                                        <div class="group-description"><?php echo htmlspecialchars($g['description'] ?? ''); ?></div>
                                    </div>
                                    <div class="group-actions">
                                        <?php if ($g['name'] !== 'default'): ?>
                                            <button class="btn btn-secondary btn-sm" onclick="editGroupInline('<?php echo $g['id']; ?>')">✏️ Edit</button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteGroup('<?php echo $g['id']; ?>')">🗑️ Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="group-edit" style="display: none;">
                                    <div class="edit-form">
                                        <input type="text" class="edit-name" value="<?php echo htmlspecialchars($g['name']); ?>" <?php echo ($g['name'] === 'default') ? 'readonly' : ''; ?>>
                                        <input type="text" class="edit-description" value="<?php echo htmlspecialchars($g['description'] ?? ''); ?>" placeholder="Description">
                                        <div class="edit-actions">
                                            <button class="btn btn-sm" onclick="saveGroupInline('<?php echo $g['id']; ?>')">💾 Save</button>
                                            <button class="btn btn-secondary btn-sm" onclick="cancelEditInline('<?php echo $g['id']; ?>')">❌ Cancel</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // File upload handling
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            
            // Set the files to the file input so it works with the normal flow
            const fileInput = document.getElementById('fileInput');
            fileInput.files = files;
            
            // Update the display
            updateFileDisplay(files);
        });
        
        // File input change handler is now handled by updateFileDisplay() in the DOMContentLoaded section
        
        function uploadFiles(files, groupOverride = null, duplicates = null) {
            const formData = new FormData();

            // Get selected groups (or use override)
            const selectedGroups = groupOverride || Array.from(document.querySelectorAll('input[name="groups[]"]:checked')).map(cb => cb.value);

            // Ensure at least one group is selected (default to 'default' if none)
            if (selectedGroups.length === 0) {
                selectedGroups.push('default');
            }

            for (let i = 0; i < files.length; i++) {
                formData.append('files[]', files[i]);
            }

            // Send groups as array
            selectedGroups.forEach(groupId => {
                formData.append('groups[]', groupId);
            });

            // Send duplicates info if provided
            if (duplicates) {
                formData.append('duplicates', JSON.stringify(duplicates));
                formData.append('replace_duplicates', '1');
            }
            
            const progressDiv = document.getElementById('uploadProgress');
            const statusDiv = document.getElementById('uploadStatus');
            const progressFill = document.getElementById('progressFill');
            
            progressDiv.style.display = 'block';

            // Different message for replacement vs normal upload
            const isReplacement = duplicates !== null && duplicates.length > 0;
            if (isReplacement) {
                statusDiv.innerHTML = '🔄 Replacing ' + duplicates.length + ' duplicate file' + (duplicates.length > 1 ? 's' : '') + '...';
            } else {
                statusDiv.innerHTML = '📤 Uploading ' + files.length + ' file' + (files.length > 1 ? 's' : '') + '...';
            }
            progressFill.style.width = '0%';
            
            // Simulate progress (since we can't track real upload progress easily)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += Math.random() * 30;
                if (progress > 90) progress = 90;
                progressFill.style.width = progress + '%';
            }, 200);
            
            // Extract db parameter from current URL
            const urlParams = new URLSearchParams(window.location.search);
            const dbParam = urlParams.get('db');
            const uploadUrl = dbParam ? `?action=upload&db=${encodeURIComponent(dbParam)}` : '?action=upload';

            fetch(uploadUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearInterval(progressInterval);
                progressFill.style.width = '100%';
                
                if (data.success) {
                    const successful = data.results.filter(r => r.success).length;
                    const failed = data.results.filter(r => !r.success).length;

                    // Different message for replacement vs normal upload
                    const isReplacement = duplicates !== null && duplicates.length > 0;
                    const actionText = isReplacement ? 'replaced' : 'uploaded';

                    statusDiv.innerHTML = `✅ Successfully ${actionText} ${successful} file${successful > 1 ? 's' : ''}` +
                        (failed > 0 ? ` (${failed} failed)` : '') +
                        '<br><small>Closing modal and refreshing...</small>';

                    setTimeout(() => {
                        const modal = document.getElementById('uploadModal');
                        if (modal.hidePopover) {
                            modal.hidePopover();
                        } else {
                            modal.style.display = 'none';
                        }
                        // Re-enable upload button after successful upload
                        enableUploadButton();
                        location.reload();
                    }, 2000);
                } else {
                    // Different error message for replacement vs normal upload
                    const isReplacement = duplicates !== null && duplicates.length > 0;
                    const actionText = isReplacement ? 'Replacement' : 'Upload';
                    statusDiv.innerHTML = `❌ ${actionText} failed: ` + data.error;
                    // Re-enable upload button after failed upload
                    enableUploadButton();
                }
            })
            .catch(error => {
                clearInterval(progressInterval);
                // Different error message for replacement vs normal upload
                const isReplacement = duplicates !== null && duplicates.length > 0;
                const actionText = isReplacement ? 'Replacement' : 'Upload';
                statusDiv.innerHTML = `❌ ${actionText} error: ` + error.message;
                // Re-enable upload button after network error
                enableUploadButton();
            });
        }
        
        function processEmbeddings() {
            const btn = document.getElementById('processBtn');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            fetch('?action=process_embeddings', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const processed = data.results.filter(r => r.success).length;
                    const failed = data.results.filter(r => !r.success).length;
                    alert(`Processed ${processed} embeddings successfully. ${failed} failed.`);
                    location.reload();
                } else {
                    alert('Processing failed: ' + data.error);
                }
                btn.disabled = false;
                btn.textContent = 'Process Embeddings';
            })
            .catch(error => {
                alert('Processing error: ' + error.message);
                btn.disabled = false;
                btn.textContent = 'Process Embeddings';
            });
        }
        
        function performSearch() {
            const query = document.getElementById('searchQuery').value;

            if (!query.trim()) {
                alert('Please enter a search query');
                return;
            }

            const formData = new FormData();
            formData.append('query', query);
            formData.append('limit', document.getElementById('searchLimit').value);
            formData.append('threshold', document.getElementById('searchThreshold').value);
            formData.append('group', document.getElementById('searchGroup').value);

            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #007cba; border-radius: 50%; animation: spin 1s linear infinite;"></div><p>Searching for: "' + query + '"...</p></div>';

            // Extract db parameter from current URL
            const urlParams = new URLSearchParams(window.location.search);
            const dbParam = urlParams.get('db');
            const fetchUrl = dbParam ? `?action=search&db=${encodeURIComponent(dbParam)}` : '?action=search';

            fetch(fetchUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySearchResults(data.results, query);
                } else {
                    resultsDiv.innerHTML =
                        '<div class="alert alert-error">❌ Search failed: ' + data.error + '</div>';
                }
            })
            .catch(error => {
                resultsDiv.innerHTML =
                    '<div class="alert alert-error">❌ Search error: ' + error.message + '</div>';
            });
        }
        
        function displaySearchResults(results, query) {
            const container = document.getElementById('searchResults');
            
            if (results.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                        <h3>No results found</h3>
                        <p>No documents match your search for "<strong>${query}</strong>"</p>
                        <p style="font-size: 14px; margin-top: 20px;">
                            💡 <strong>Tips:</strong><br>
                            • Try different keywords or phrases<br>
                            • Check if documents are embedded<br>
                            • Lower the similarity threshold<br>
                            • Try broader search terms
                        </p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 10px; background: #e8f5e8; border-radius: 6px; border-left: 4px solid #4caf50;">
                    <div>
                        <strong>✅ Found ${results.length} result${results.length > 1 ? 's' : ''}</strong> for "<em>${query}</em>"
                    </div>
                    <button onclick="copyResultsToClipboard(${JSON.stringify(results).replace(/"/g, '&quot;')})" style="background: #007cba; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px;">
                        📋 Copy Results
                    </button>
                </div>
            `;
            
            results.forEach((result, index) => {
                const similarity = (result.similarity * 100).toFixed(1);
                const isLongContent = result.content.length > 400;
                const truncatedContent = isLongContent ? result.content.substring(0, 400) + '...' : result.content;
                const fullContent = result.content;

                html += `
                    <div class="result-item" style="position: relative;">
                        <div class="similarity" style="background: ${similarity >= 90 ? '#4caf50' : similarity >= 70 ? '#ff9800' : '#f44336'}">${similarity}%</div>
                        <div class="source" style="margin-bottom: 8px;">
                            <strong style="color: #007cba;">📄 ${result.title}</strong>
                            <span style="color: #666;"> • 🏷️ ${result.group_name} • 🧩 Chunk ${result.chunk_index + 1}</span>
                        </div>
                        <div class="content" style="line-height: 1.6; word-wrap: break-word; overflow-wrap: break-word;">
                            <span id="content-truncated-${index}" style="word-wrap: break-word; overflow-wrap: break-word;">${highlightSearchTerms(truncatedContent, query)}</span>
                            <span id="content-full-${index}" style="display: none; word-wrap: break-word; overflow-wrap: break-word;">${highlightSearchTerms(fullContent, query)}</span>
                            ${isLongContent ? `<button onclick="toggleContent(${index})" id="btn-expand-content${index}" style="background: none; border: none; color: #007cba; cursor: pointer; padding: 0; margin-left: 8px; font-size: 14px; text-decoration: underline;">Show More</button>` : ''}
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function highlightSearchTerms(text, query) {
            const words = query.toLowerCase().split(/\s+/).filter(word => word.length > 2);
            let highlightedText = text;

            words.forEach(word => {
                const regex = new RegExp(`(${word})`, 'gi');
                highlightedText = highlightedText.replace(regex, '<mark style="background: #ffeb3b; padding: 1px 2px; border-radius: 2px;">$1</mark>');
            });

            return highlightedText;
        }

        async function copyResultsToClipboard(results) {
            let markdownContent = '';

            results.forEach((result, index) => {
                const similarityPercent = (result.similarity * 100).toFixed(1);
                const fullContent = result.content; // Use full content, not truncated

                markdownContent += `# Document:\n${result.title}\n\n`;
                markdownContent += `# Content Snippet:\n${fullContent}\n\n`;
                markdownContent += `# Score:\n${similarityPercent}%\n\n`;

                if (index < results.length - 1) {
                    markdownContent += '---\n\n';
                }
            });

            try {
                await navigator.clipboard.writeText(markdownContent);

                // Show success feedback
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copied!';
                button.style.background = '#4caf50';

                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '#007cba';
                }, 2000);

            } catch (error) {
                console.error('Failed to copy results:', error);

                // Fallback for older browsers
                try {
                    const textArea = document.createElement('textarea');
                    textArea.value = markdownContent;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);

                    alert('Results copied to clipboard!');
                } catch (fallbackError) {
                    console.error('Fallback copy failed:', fallbackError);
                    alert('Failed to copy results. Please try again.');
                }
            }
        }

        function toggleContent(index) {
            const truncatedElement = document.getElementById(`content-truncated-${index}`);
            const fullElement = document.getElementById(`content-full-${index}`);
            const button = document.getElementById(`btn-expand-content${index}`);

            if (truncatedElement.style.display === 'none') {
                // Currently showing full content, switch to truncated
                truncatedElement.style.display = 'inline';
                fullElement.style.display = 'none';
                button.textContent = 'Show More';
            } else {
                // Currently showing truncated content, switch to full
                truncatedElement.style.display = 'none';
                fullElement.style.display = 'inline';
                button.textContent = 'Show Less';
            }
        }

        function deleteDocument(documentId) {
            if (!confirm('Are you sure you want to delete this document and all its embeddings?')) {
                return;
            }

            // Get current database from URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const currentDb = urlParams.get('db');

            const formData = new FormData();
            formData.append('document_id', documentId);
            if (currentDb) {
                formData.append('db', currentDb);
            }

            fetch('?action=delete_document', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to delete document');
                }
            })
            .catch(error => {
                alert('Delete error: ' + error.message);
            });
        }
        
        // Group management functions
        function editGroup(id, name, description, color) {
            document.getElementById('group_id').value = id;
            document.getElementById('group_name').value = name;
            document.getElementById('group_description').value = description || '';
            document.getElementById('group_color').value = color || '#007cba';
        }
        
        function deleteGroup(id) {
            if (!confirm('Are you sure you want to delete this group? Documents in this group will be moved to the default group.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('delete_group', '1');
            formData.append('id', id);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to delete group: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Delete error: ' + error.message);
            });
        }
        
        function saveGroup(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('save_group', '1');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    resetGroupForm();
                    location.reload();
                } else {
                    alert('Failed to save group: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Save error: ' + error.message);
            });
        }
        
        function resetGroupForm() {
            document.getElementById('groupForm').reset();
            document.getElementById('group_id').value = '';
            document.getElementById('group_color').value = '#007cba';
        }
        
        // New enhanced functions
        
        // Upload modal functions
        function showUploadModal() {
            const modal = document.getElementById('uploadModal');
            if (modal.showPopover) {
                modal.showPopover();
            } else {
                modal.style.display = 'flex';
            }
        }
        
        // Helper function to re-enable the upload button
        function enableUploadButton() {
            const uploadButton = document.querySelector('button[onclick="startUpload()"]');
            if (uploadButton) {
                uploadButton.disabled = false;
                uploadButton.textContent = '📤 Upload Files';
            }
        }

        function startUpload() {
            // Disable the upload button to prevent spam clicking
            const uploadButton = document.querySelector('button[onclick="startUpload()"]');
            if (uploadButton) {
                uploadButton.disabled = true;
                uploadButton.textContent = '🔄 Processing...';
            }

            const fileInput = document.getElementById('fileInput');
            const allFiles = Array.from(fileInput.files);
            const maxFiles = 20; // PHP's max_file_uploads default limit

            if (allFiles.length === 0) {
                // Re-enable button if no files selected
                if (uploadButton) {
                    uploadButton.disabled = false;
                    uploadButton.textContent = '📤 Upload Files';
                }
                alert('Please select files to upload');
                return;
            }

            // Check for duplicates in all selected files
            checkForDuplicates(allFiles);
        }

        // Check for duplicate files before upload
        function checkForDuplicates(files) {
            const filenames = files.map(f => f.name);

            // Extract db parameter from current URL
            const urlParams = new URLSearchParams(window.location.search);
            const dbParam = urlParams.get('db');

            fetch('?action=check_duplicates&db=' + encodeURIComponent(dbParam), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'filenames=' + encodeURIComponent(JSON.stringify(filenames))
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.duplicates.length > 0) {
                        // Show duplicate modal
                        showDuplicateModal(data.duplicates, files);
                    } else {
                        // No duplicates, proceed with upload
                        uploadFiles(files);
                    }
                } else {
                    alert('Error checking for duplicates: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error checking duplicates:', error);
                alert('Error checking for duplicates. Proceeding with upload.');
                uploadFiles(files);
            });
        }

        // Show the duplicate files modal
        function showDuplicateModal(duplicates, allFiles) {
            const duplicateList = document.getElementById('duplicateList');
            duplicateList.innerHTML = '';

            duplicates.forEach(duplicate => {
                const div = document.createElement('div');
                div.style.cssText = 'padding: 8px; margin-bottom: 8px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;';
                div.innerHTML = `<strong>${duplicate.filename}</strong><br><small style="color: #856404;">Existing: ${duplicate.existing_title}.${duplicate.existing_type}</small>`;
                duplicateList.appendChild(div);
            });

            // Store files for later use
            window.pendingUploadFiles = allFiles;
            window.pendingDuplicates = duplicates;

            // Show modal
            const modal = document.getElementById('duplicateModal');
            if (modal.showPopover) {
                modal.showPopover();
            } else {
                modal.style.display = 'flex';
            }
        }

        // Close duplicate modal
        function closeDuplicateModal() {
            const modal = document.getElementById('duplicateModal');
            if (modal.hidePopover) {
                modal.hidePopover();
            } else {
                modal.style.display = 'none';
            }
        }

        // Function to update the file display
        function updateFileDisplay(files) {
            const instructions = document.getElementById('uploadInstructions');
            const feedback = document.getElementById('uploadFeedback');
            const fileList = document.getElementById('fileList');
            const maxFiles = 20;

            if (files.length > 0) {
                // Show file list, hide instructions
                instructions.style.display = 'none';
                fileList.style.display = 'block';

                // Show/hide feedback message
                if (files.length > maxFiles) {
                    feedback.textContent = `⚠️ Maximum ${maxFiles} files allowed. ${files.length - maxFiles} file(s) will be ignored during upload.`;
                    feedback.style.display = 'block';
                } else {
                    feedback.style.display = 'none';
                }

                // Clear existing list
                fileList.innerHTML = '';

                // Add each file to the list
                Array.from(files).forEach((file, index) => {
                    const li = document.createElement('li');

                    // Add over-limit class if file is beyond the limit
                    if (index >= maxFiles) {
                        li.classList.add('over-limit');
                    }

                    // Create file name span
                    const nameSpan = document.createElement('span');
                    nameSpan.textContent = file.name;
                    li.appendChild(nameSpan);

                    // Create remove button
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-file';
                    removeBtn.textContent = '×';
                    removeBtn.title = 'Remove file';
                    removeBtn.onclick = () => removeFile(index);
                    li.appendChild(removeBtn);

                    fileList.appendChild(li);
                });
            } else {
                // Show instructions, hide file list and feedback
                instructions.style.display = 'block';
                fileList.style.display = 'none';
                feedback.style.display = 'none';
                fileList.innerHTML = '';
            }
        }

        // Function to remove a file at specific index
        function removeFile(indexToRemove) {
            const fileInput = document.getElementById('fileInput');
            const currentFiles = Array.from(fileInput.files);

            // Remove the file at the specified index
            currentFiles.splice(indexToRemove, 1);

            // Create a new FileList-like object
            const dt = new DataTransfer();
            currentFiles.forEach(file => dt.items.add(file));

            // Update the file input
            fileInput.files = dt.files;

            // Update the display
            updateFileDisplay(fileInput.files);
        }

        // Handle duplicate resolution
        function handleDuplicates(action) {
            closeDuplicateModal();

            if (action === 'ignore') {
                // Filter out duplicate files from all selected files
                const filesToUpload = window.pendingUploadFiles.filter(file => {
                    return !window.pendingDuplicates.some(dup => dup.filename === file.name);
                });

                // Update the file input to only contain non-duplicate files
                const dt = new DataTransfer();
                filesToUpload.forEach(file => dt.items.add(file));
                document.getElementById('fileInput').files = dt.files;

                // Update the display to show only non-duplicate files
                updateFileDisplay(filesToUpload);

                // Re-enable the upload button since we're reopening the modal for user interaction
                const uploadButton = document.querySelector('button[onclick="startUpload()"]');
                if (uploadButton) {
                    uploadButton.disabled = false;
                    uploadButton.textContent = '📤 Upload Files';
                }

                // Always reopen the upload modal after handling duplicates
                const uploadModal = document.getElementById('uploadModal');
                if (uploadModal.showPopover) {
                    uploadModal.showPopover();
                } else {
                    uploadModal.style.display = 'flex';
                }

                // Show feedback message if no files remain
                if (filesToUpload.length === 0) {
                    const feedback = document.getElementById('uploadFeedback');
                    if (feedback) {
                        feedback.textContent = 'ℹ️ All selected files were duplicates and have been removed. Please select new files to upload.';
                        feedback.style.display = 'block';
                    }
                }
            } else if (action === 'replace') {
                // Show progress feedback for replacement
                const uploadModal = document.getElementById('uploadModal');
                const uploadFeedback = document.getElementById('uploadFeedback');
                const fileList = document.getElementById('fileList');
                const progressDiv = document.getElementById('uploadProgress');
                const statusDiv = document.getElementById('uploadStatus');

                // Show upload modal with replacement progress
                if (uploadModal.showPopover) {
                    uploadModal.showPopover();
                } else {
                    uploadModal.style.display = 'flex';
                }

                // Proceed with replacement upload
                uploadFiles(window.pendingUploadFiles, null, window.pendingDuplicates);
            }

            // Clean up
            delete window.pendingUploadFiles;
            delete window.pendingDuplicates;
        }

        function cancelUpload() {
            // Clear the upload area display
            const instructions = document.getElementById('uploadInstructions');
            const feedback = document.getElementById('uploadFeedback');
            const fileList = document.getElementById('fileList');

            if (instructions && feedback && fileList) {
                instructions.style.display = 'block';
                feedback.style.display = 'none';
                fileList.style.display = 'none';
                fileList.innerHTML = '';
            }

            // Clear the file input
            const fileInput = document.getElementById('fileInput');
            if (fileInput) {
                fileInput.value = '';
                // Try to clear files property as well
                try {
                    fileInput.files = new DataTransfer().files;
                } catch (e) {
                    // Fallback for browsers that don't support DataTransfer
                    const newInput = fileInput.cloneNode(true);
                    fileInput.parentNode.replaceChild(newInput, fileInput);
                }
            }

            // Close the modal
            const modal = document.getElementById('uploadModal');
            if (modal) {
                if (modal.hidePopover) {
                    modal.hidePopover();
                } else {
                    modal.style.display = 'none';
                }
            }

            // Re-enable upload button when cancelled
            enableUploadButton();
        }

        // Group modal functions
        function showCreateGroupModal() {
            const modal = document.getElementById('createGroupModal');
            if (modal.showPopover) {
                modal.showPopover();
            } else {
                modal.style.display = 'flex';
            }
            document.getElementById('new_group_name').focus();
        }
        
        function createGroup(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('save_group', '1');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('createGroupModal');
                    if (modal.hidePopover) {
                        modal.hidePopover();
                    } else {
                        modal.style.display = 'none';
                    }
                    document.getElementById('createGroupForm').reset();
                    location.reload();
                } else {
                    alert('Failed to create group: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Create error: ' + error.message);
            });
        }
        
        // Inline group editing functions
        function editGroupInline(groupId) {
            const groupItem = document.getElementById('group-' + groupId);
            const display = groupItem.querySelector('.group-display');
            const edit = groupItem.querySelector('.group-edit');
            
            display.style.display = 'none';
            edit.style.display = 'block';
            
            const nameInput = edit.querySelector('.edit-name');
            nameInput.focus();
            nameInput.select();
        }
        
        function cancelEditInline(groupId) {
            const groupItem = document.getElementById('group-' + groupId);
            const display = groupItem.querySelector('.group-display');
            const edit = groupItem.querySelector('.group-edit');
            
            display.style.display = 'flex';
            edit.style.display = 'none';
        }
        
        function saveGroupInline(groupId) {
            const groupItem = document.getElementById('group-' + groupId);
            const nameInput = groupItem.querySelector('.edit-name');
            const descInput = groupItem.querySelector('.edit-description');
            
            const formData = new FormData();
            formData.append('save_group', '1');
            formData.append('id', groupId);
            formData.append('name', nameInput.value);
            formData.append('description', descInput.value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to save group: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Save error: ' + error.message);
            });
        }

        // Edit document groups functions
        function editDocumentGroups(documentId, documentTitle) {
            const modal = document.getElementById('editDocumentGroupsModal');
            const titleElement = document.getElementById('editGroupsDocumentTitle');
            const checkboxesElement = document.getElementById('editGroupsCheckboxes');
            const documentIdElement = document.getElementById('edit_groups_document_id');

            // Set document info
            titleElement.textContent = 'Document: ' + documentTitle;
            documentIdElement.value = documentId;

            // Load available groups and current selections
            const currentDb = document.getElementById('current_db')?.value || '';
            fetch('?action=get_document_groups&document_id=' + documentId + '&db=' + encodeURIComponent(currentDb))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const allGroups = data.all_groups || [];
                        const documentGroups = data.document_groups || [];

                        // Create checkboxes HTML
                        let checkboxesHtml = '';
                        allGroups.forEach(group => {
                            const isSelected = documentGroups.some(dg => dg.group_id === group.id);
                            checkboxesHtml += `
                                <div class="checkbox-group" style="margin-bottom: 8px;">
                                    <label style="margin-bottom: 0; font-weight: normal; display: flex; align-items: center; cursor: pointer;">
                                        <input type="checkbox"
                                               name="group_ids[]"
                                               value="${group.id}"
                                               ${isSelected ? 'checked' : ''}
                                               style="margin-right: 10px;">
                                        ${group.name}
                                        ${group.description ? `<small style="margin: 0 2px; color: #666;"> - ${group.description}</small>` : ''}
                                    </label>
                                </div>
                            `;
                        });

                        checkboxesElement.innerHTML = checkboxesHtml;

                        // Show modal
                        if (modal.showPopover) {
                            modal.showPopover();
                        } else {
                            modal.style.display = 'flex';
                        }
                    } else {
                        alert('Failed to load groups: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Error loading groups: ' + error.message);
                });
        }

        function updateDocumentGroups(event) {
            event.preventDefault();

            const formData = new FormData(event.target);
            formData.append('action', 'update_document_groups');

            const selectedGroups = formData.getAll('group_ids[]');
            if (selectedGroups.length === 0) {
                alert('Please select at least one group.');
                return;
            }

            const currentDb = document.getElementById('current_db')?.value || '';
            formData.append('db', currentDb);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('editDocumentGroupsModal');
                    if (modal.hidePopover) {
                        modal.hidePopover();
                    } else {
                        modal.style.display = 'none';
                    }
                    location.reload();
                } else {
                    alert('Failed to update groups: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Update error: ' + error.message);
            });
        }

        // Document content toggle
        function toggleDocumentContent(header) {
            const documentItem = header.closest('.document-item');
            const content = documentItem.querySelector('.document-content');
            const expandIcon = documentItem.querySelector('.expand-icon');
            
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                expandIcon.textContent = '📖';
                documentItem.classList.add('expanded');
            } else {
                content.style.display = 'none';
                expandIcon.textContent = '📄';
                documentItem.classList.remove('expanded');
            }
        }
        
        // Document filtering
        function filterDocuments() {
            const groupFilter = document.getElementById('filter_group').value;
            const statusFilter = document.getElementById('filter_status').value;
            const sortBy = document.getElementById('sort_by').value;
            
            // Update URL with current filters
            const currentUrl = new URL(window.location);
            
            // Update group filter
            if (groupFilter) {
                currentUrl.searchParams.set('doc_group', groupFilter);
            } else {
                currentUrl.searchParams.delete('doc_group');
            }
            
            // Update status filter
            if (statusFilter) {
                currentUrl.searchParams.set('doc_status', statusFilter);
            } else {
                currentUrl.searchParams.delete('doc_status');
            }
            
            // Update sort parameter
            if (sortBy && sortBy !== 'created_desc') { // Don't add default sort to URL
                currentUrl.searchParams.set('doc_sort', sortBy);
            } else {
                currentUrl.searchParams.delete('doc_sort');
            }
            
            // Update URL without reloading the page
            window.history.replaceState({}, '', currentUrl);
            
            const documents = Array.from(document.querySelectorAll('.document-item'));
            
            // Filter documents
            documents.forEach(doc => {
                let show = true;
                
                if (groupFilter && doc.dataset.group !== groupFilter) {
                    show = false;
                }
                
                if (statusFilter && doc.dataset.status !== statusFilter) {
                    show = false;
                }
                
                doc.style.display = show ? 'block' : 'none';
            });
            
            // Sort visible documents
            const visibleDocs = documents.filter(doc => doc.style.display !== 'none');
            const container = document.getElementById('documentsList');
            
            visibleDocs.sort((a, b) => {
                switch (sortBy) {
                    case 'created_desc':
                        return new Date(b.dataset.created) - new Date(a.dataset.created);
                    case 'created_asc':
                        return new Date(a.dataset.created) - new Date(b.dataset.created);
                    case 'title_asc':
                        return a.dataset.title.localeCompare(b.dataset.title);
                    case 'title_desc':
                        return b.dataset.title.localeCompare(a.dataset.title);
                    case 'type_asc':
                        return a.dataset.type.localeCompare(b.dataset.type);
                    case 'size_desc':
                        return parseInt(b.dataset.size) - parseInt(a.dataset.size);
                    case 'size_asc':
                        return parseInt(a.dataset.size) - parseInt(b.dataset.size);
                    default:
                        return 0;
                }
            });
            
            // Re-append sorted documents
            visibleDocs.forEach(doc => container.appendChild(doc));
        }
        
        // Enhanced search functions
        function updateThresholdDisplay() {
            const threshold = document.getElementById('searchThreshold');
            const display = document.getElementById('thresholdValue');
            if (threshold && display) {
                display.textContent = Math.round(threshold.value * 100) + '%';
            }
        }
        
        // Password change function
        function changePassword() {
            const currentPassword = document.getElementById('settings_current_password').value;
            const newPassword = document.getElementById('settings_password').value;
            const confirmPassword = document.getElementById('settings_password_confirm').value;
            const hashType = document.getElementById('settings_password_hash').value;
            
            if (!currentPassword || !newPassword || !confirmPassword) {
                alert('Please fill in all password fields');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                alert('New passwords do not match');
                return;
            }
            
            if (newPassword.length < 6) {
                alert('New password must be at least 6 characters long');
                return;
            }
            
            const formData = new FormData();
            formData.append('change_password_only', '1');
            formData.append('current_password', currentPassword);
            formData.append('password', newPassword);
            formData.append('password_confirm', confirmPassword);
            formData.append('password_hash', hashType);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    alert('Password changed successfully!');
                    // Clear password fields
                    document.getElementById('settings_current_password').value = '';
                    document.getElementById('settings_password').value = '';
                    document.getElementById('settings_password_confirm').value = '';
                } else {
                    alert('Failed to change password: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Password change error: ' + error.message);
            });
        }
        
        // Settings/DB selector toggle helpers
        function toggleSettings() {
            const panel = document.getElementById('settingsPanel');
            const dbSelector = document.querySelector('.database-selector');
            const mainContent = document.querySelector('.main-content');
            const dashboard = document.querySelector('.dashboard');
            const documentsPanel = document.getElementById('documentsPanel');
            // Find groups panel more reliably
            const groupsPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Groups')
            );
            // Find Vector Search panel
            const vectorSearchPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Vector Search')
            );

            const opening = (panel.style.display === 'none' || panel.style.display === '');
            panel.style.display = opening ? 'block' : 'none';
            if (dbSelector) dbSelector.style.display = 'none'; // never show DB selector while toggling settings
            if (mainContent) mainContent.style.display = opening ? 'none' : 'grid';
            if (dashboard) dashboard.style.display = opening ? 'none' : 'grid';
            if (documentsPanel) documentsPanel.style.display = opening ? 'none' : 'block';
            if (groupsPanel) groupsPanel.style.display = opening ? 'none' : 'block';
            if (vectorSearchPanel) vectorSearchPanel.style.display = opening ? 'none' : 'block';

            // Update URL with section parameter
            const currentUrl = new URL(window.location);
            if (opening) {
                currentUrl.searchParams.set('section', 'settings');
            } else {
                currentUrl.searchParams.delete('section');
            }
            window.history.replaceState({}, '', currentUrl);
        }
        
        function returnFromSettingsToMain() {
            const panel = document.getElementById('settingsPanel');
            const dbSelector = document.querySelector('.database-selector');
            const mainContent = document.querySelector('.main-content');
            const dashboard = document.querySelector('.dashboard');
            const documentsPanel = document.getElementById('documentsPanel');
            // Find groups panel more reliably
            const groupsPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Groups')
            );
            // Find Vector Search panel
            const vectorSearchPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Vector Search')
            );

            panel.style.display = 'none';
            // Only show database selector if no database is connected, otherwise show main content
            if (dbSelector && (!mainContent || mainContent.style.display === 'none')) {
                dbSelector.style.display = 'block';
            } else {
                if (dbSelector) dbSelector.style.display = 'none';
                if (mainContent) mainContent.style.display = 'grid';
                if (dashboard) dashboard.style.display = 'grid';
                if (documentsPanel) documentsPanel.style.display = 'block';
                if (groupsPanel) groupsPanel.style.display = 'block';
                if (vectorSearchPanel) vectorSearchPanel.style.display = 'block';
            }

            // Remove section parameter when returning to main
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.delete('section');
            window.history.replaceState({}, '', currentUrl);
        }
        
        function toggleDatabaseSelector() {
            const dbSelector = document.querySelector('.database-selector');
            const mainContent = document.querySelector('.main-content');
            const dashboard = document.querySelector('.dashboard');
            const settings = document.getElementById('settingsPanel');
            const documentsPanel = document.getElementById('documentsPanel');
            // Find groups panel more reliably
            const groupsPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Groups')
            );
            // Find Vector Search panel
            const vectorSearchPanel = Array.from(document.querySelectorAll('.panel')).find(p =>
                p.querySelector('h2') && p.querySelector('h2').textContent.includes('Vector Search')
            );

            if (!dbSelector) return;
            const opening = (dbSelector.style.display === 'none' || dbSelector.style.display === '');
            dbSelector.style.display = opening ? 'block' : 'none';
            if (settings) settings.style.display = 'none';
            if (mainContent) mainContent.style.display = opening ? 'none' : 'grid';
            if (dashboard) dashboard.style.display = opening ? 'none' : 'grid';
            if (documentsPanel) documentsPanel.style.display = opening ? 'none' : 'block';
            if (groupsPanel) groupsPanel.style.display = opening ? 'none' : 'block';
            if (vectorSearchPanel) vectorSearchPanel.style.display = opening ? 'none' : 'block';

            // Update URL with section parameter
            const currentUrl = new URL(window.location);
            if (opening) {
                currentUrl.searchParams.set('section', 'databases');
            } else {
                currentUrl.searchParams.delete('section');
            }
            window.history.replaceState({}, '', currentUrl);
        }
        
        // Documents bulk actions
        function selectAllPending(select) {
            const boxes = document.querySelectorAll('.doc-select');
            boxes.forEach(cb => { cb.checked = !!select; });
            updateProcessSelectedButtonText();
        }
        
        function updateProcessSelectedButtonText() {
            const checkboxes = document.querySelectorAll('.doc-select:checked');
            const selectedCount = checkboxes.length;
            const processSelectedBtn = document.getElementById('processSelectedBtn');
            
            if (processSelectedBtn) {
                if (selectedCount === 0) {
                    processSelectedBtn.textContent = '⚡ Process Selected Pending';
                } else {
                    processSelectedBtn.textContent = `⚡ Process Selected Pending (${selectedCount} selected)`;
                }
            }
        }
        
        function processSelectedPending() {
            const checkboxes = document.querySelectorAll('.doc-select:checked');
            if (!checkboxes.length) { alert('Select at least one document with pending items.'); return; }
            const ids = Array.from(checkboxes).map(cb => cb.value);

            // Show progress area and update button
            const progressArea = document.getElementById('processProgressArea');
            const progressFill = document.getElementById('processProgressFill');
            const statusDiv = document.getElementById('processStatus');
            const processBtn = document.getElementById('processSelectedBtn');

            progressArea.style.display = 'flex';
            progressFill.style.width = '0%';
            statusDiv.innerHTML = '🔄 Starting processing...';
            processBtn.disabled = true;
            processBtn.textContent = '⏳ Processing...';

            // Track processing state
            let totalProcessed = 0;
            let totalSuccessful = 0;
            let totalFailed = 0;
            let batchIndex = 0;
            let startTime = Date.now();

            function processBatch() {
                const formData = new FormData();
                ids.forEach(id => formData.append('document_ids[]', id));
                formData.append('batch_size', '5'); // Process 5 at a time for better progress visibility
                formData.append('batch_index', batchIndex.toString());

                fetch('?action=process_selected_pending&db=<?php echo urlencode($current_db ?? ''); ?>', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const results = data.results || [];
                            const batchSuccessful = results.filter(r => r.success).length;
                            const batchFailed = results.filter(r => !r.success).length;

                            totalProcessed += results.length;
                            totalSuccessful += batchSuccessful;
                            totalFailed += batchFailed;

                            // Update progress
                            const progressPercent = data.progress_percent || 0;
                            progressFill.style.width = progressPercent + '%';

                            // Calculate timing
                            const elapsed = Math.round((Date.now() - startTime) / 1000);
                            const rate = elapsed > 0 ? Math.round(totalProcessed / elapsed) : 0;

                            // Update status with concise information
                            const failedText = totalFailed > 0 ? ` - ${totalFailed} failed` : '';
                            statusDiv.innerHTML = `🔄 Processing embeddings...<br>` +
                                `<small>Progress: ${data.completed}/${data.total} (${progressPercent}%)${failedText}</small>`;

                            if (data.has_more) {
                                // Continue with next batch
                                batchIndex++;
                                setTimeout(processBatch, 100); // Small delay between batches
                            } else {
                                // Processing complete
                                progressFill.style.width = '100%';
                                const totalTime = Math.round((Date.now() - startTime) / 1000);

                                statusDiv.innerHTML = `✅ Completed: ${totalSuccessful} processed${totalFailed > 0 ? ` (${totalFailed} failed)` : ''}<br>` +
                                    `<small>Refreshing page...</small>`;

                                processBtn.textContent = '✅ Completed';

                                setTimeout(() => {
                                    location.reload();
                                }, 2000);
                            }
                        } else {
                            statusDiv.innerHTML = '❌ Processing failed: ' + data.error;
                            progressFill.style.background = '#dc3545'; // Red for error
                            processBtn.disabled = false;
                            processBtn.textContent = '⚡ Process Selected Pending';
                        }
                    })
                    .catch(err => {
                        statusDiv.innerHTML = '❌ Error: ' + err.message;
                        progressFill.style.background = '#dc3545'; // Red for error
                        progressFill.style.width = '100%';
                        processBtn.disabled = false;
                        processBtn.textContent = '⚡ Process Selected Pending';
                    });
            }

            // Start processing
            processBatch();
        }
        
        function requeueAllFailed() {
            if (!confirm('Re-queue all failed embeddings in this database?')) return;
            const fd = new FormData();
            fd.append('all', '1');
            fetch('?action=requeue_failed&db=<?php echo urlencode($current_db ?? ''); ?>', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => { if (data.success) { alert('Re-queued ' + data.requeued + ' failed items.'); location.reload(); } else { alert('Re-queue failed: ' + data.error); } })
                .catch(err => alert('Error: ' + err.message));
        }

        function showProviderConfig(provider) {
            // Hide all provider configs
            const configs = document.querySelectorAll('.provider-settings');
            for (let i = 0; i < configs.length; i++) {
                configs[i].style.display = 'none';
            }
            
            // Show selected provider config
            const selectedConfig = document.getElementById('settings_' + provider + '_config');
            if (selectedConfig) {
                selectedConfig.style.display = 'block';
            }
        }
        
        function saveAndTestProvider(provider) {
            // Collect form data
            const formData = new FormData();
            formData.append('update_settings', '1');

            // Add provider selection
            formData.append('default_provider', provider);

            // Add provider-specific settings
            if (provider === 'openai') {
                formData.append('openai_api_key', document.getElementById('settings_openai_key').value);
                formData.append('openai_model', document.getElementById('settings_openai_model').value);
            } else if (provider === 'gemini') {
                formData.append('gemini_api_key', document.getElementById('settings_gemini_key').value);
                formData.append('gemini_model', document.getElementById('settings_gemini_model').value);
            } else if (provider === 'ollama') {
                formData.append('ollama_endpoint', document.getElementById('settings_ollama_endpoint').value);
                formData.append('ollama_model', document.getElementById('settings_ollama_model').value);
            } else if (provider === 'lmstudio') {
                formData.append('lmstudio_endpoint', document.getElementById('settings_lmstudio_endpoint').value);
                formData.append('lmstudio_api_key', document.getElementById('settings_lmstudio_key').value);
            }

            // Add other common settings (these should be preserved from existing config)
            const theme = document.getElementById('settings_theme');
            if (theme) formData.append('theme', theme.value);

            const debug = document.getElementById('settings_debug');
            if (debug) formData.append('enable_debug', debug.checked ? '1' : '0');

            const logQueries = document.getElementById('settings_log_queries');
            if (logQueries) formData.append('log_queries', logQueries.checked ? '1' : '0');

            // Save settings first
            fetch('?action=update_settings', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(saveResult => {
                if (saveResult.success) {
                    // Settings saved successfully, now test the provider
                    const testText = 'This is a test message for embedding generation.';

                    fetch('?action=test_embedding', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'test_text=' + encodeURIComponent(testText) + '&provider=' + encodeURIComponent(provider)
                    })
                    .then(response => response.json())
                    .then(testResult => {
                        if (testResult.success) {
                            alert('✅ Settings saved and embedding test successful!\nProvider: ' + testResult.provider + '\nDimensions: ' + testResult.dimensions);
                        } else {
                            alert('⚠️ Settings saved, but embedding test failed:\n' + testResult.error);
                        }
                    })
                    .catch(error => {
                        alert('⚠️ Settings saved, but test error: ' + error.message);
                    });
                } else {
                    alert('❌ Failed to save settings: ' + (saveResult.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('❌ Save error: ' + error.message);
            });
        }

        function testEmbedding() {
            const testText = 'This is a test message for embedding generation.';
            const provider = document.getElementById('settings_provider').value;

            fetch('?action=test_embedding', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'test_text=' + encodeURIComponent(testText) + '&provider=' + encodeURIComponent(provider)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Embedding test successful!\nProvider: ' + data.provider + '\nDimensions: ' + data.dimensions);
                } else {
                    alert('❌ Embedding test failed:\n' + data.error);
                }
            })
            .catch(error => {
                alert('❌ Test error: ' + error.message);
            });
        }
        
        function downloadConfig() {
            window.open('?action=download_config', '_blank');
        }
        
        // Database management functions
        function showCreateDatabaseModal() {
            const modal = document.getElementById('createDatabaseModal');
            if (modal.showPopover) {
                modal.showPopover();
            } else {
                // Fallback for browsers without popover support
                modal.style.display = 'flex';
            }
            document.getElementById('new_database_name').focus();
        }
        
        function hideCreateDatabaseModal() {
            const modal = document.getElementById('createDatabaseModal');
            if (modal.hidePopover) {
                modal.hidePopover();
            } else {
                // Fallback for browsers without popover support
                modal.style.display = 'none';
            }
            document.getElementById('createDatabaseForm').reset();
        }
        
        function createNewDatabase(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('?action=create_database', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('createDatabaseModal');
                    if (modal.hidePopover) {
                        modal.hidePopover();
                    } else {
                        modal.style.display = 'none';
                    }

                    // Get the database name from the form and redirect to the new database URL
                    const formData = new FormData(event.target);
                    const dbName = formData.get('database_name');
                    const newDbPath = 'databases/' + dbName + '.sqlite';
                    const newUrl = '?db=' + encodeURIComponent(newDbPath);
                    window.location.href = newUrl;
                } else {
                    alert('Failed to create database: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error creating database: ' + error.message);
            });
        }
        
        function showDeleteDatabaseModal(dbName, dbPathBase64) {
            const modal = document.getElementById('deleteDatabaseModal');
            if (modal.showPopover) {
                modal.showPopover();
            } else {
                // Fallback for browsers without popover support
                modal.style.display = 'flex';
            }
            // Store the base64 encoded path
            document.getElementById('delete_database_path').value = dbPathBase64;
            document.getElementById('delete_database_display').textContent = dbName;
            document.getElementById('delete_database_name_confirm').setAttribute('data-expected', dbName);
            document.getElementById('delete_confirmation').focus();
        }
        
        function hideDeleteDatabaseModal() {
            const modal = document.getElementById('deleteDatabaseModal');
            if (modal.hidePopover) {
                modal.hidePopover();
            } else {
                // Fallback for browsers without popover support
                modal.style.display = 'none';
            }
            document.getElementById('deleteDatabaseForm').reset();
        }
        
        function deleteDatabase(event) {
            event.preventDefault();
            
            const confirmation = document.getElementById('delete_confirmation').value;
            const nameConfirm = document.getElementById('delete_database_name_confirm').value;
            const expectedName = document.getElementById('delete_database_name_confirm').getAttribute('data-expected');
            
            if (confirmation.toUpperCase() !== 'DELETE') {
                alert('Please type "DELETE" to confirm');
                return;
            }
            
            if (nameConfirm !== expectedName) {
                alert('Database name does not match');
                return;
            }
            
            const formData = new FormData(event.target);
            
            fetch('?action=delete_database', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = document.getElementById('deleteDatabaseModal');
                    if (modal.hidePopover) {
                        modal.hidePopover();
                    } else {
                        modal.style.display = 'none';
                    }
                    // Redirect to main page without db parameter to show databases list
                    window.location.href = window.location.pathname;
                } else {
                    alert('Failed to delete database: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error deleting database: ' + error.message);
            });
        }
        
        // Auto-refresh stats every 30 seconds
        <?php if ($db_connected): ?>
        setInterval(() => {
            fetch('?action=get_stats&db=<?php echo urlencode($current_db); ?>')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const stats = data.stats;
                    document.querySelector('.stat-card:nth-child(1) .number').textContent = stats.documents;
                    document.querySelector('.stat-card:nth-child(2) .number').textContent = stats.chunks;
                    document.querySelector('.stat-card:nth-child(3) .number').textContent = stats.embeddings;
                    document.querySelector('.stat-card:nth-child(4) .number').textContent = stats.pending_embeddings;
                    
                    // Update process selected button text based on current selection
                    updateProcessSelectedButtonText();
                }
            });
        }, 30000);
        <?php endif; ?>
        
        // Modal event listeners and initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Handle URL parameters for sections
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');

            if (section === 'settings') {
                toggleSettings();
            } else if (section === 'databases') {
                toggleDatabaseSelector();
            }

            // Initialize threshold display
            updateThresholdDisplay();
            
            // Add threshold slider listener
            const thresholdSlider = document.getElementById('searchThreshold');
            if (thresholdSlider) {
                thresholdSlider.addEventListener('input', updateThresholdDisplay);
            }
            
            // Add search on Enter key
            const searchInput = document.getElementById('searchQuery');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch();
                    }
                });
            }
            
            // Add event listeners to document checkboxes
            document.querySelectorAll('.doc-select').forEach(checkbox => {
                checkbox.addEventListener('change', updateProcessSelectedButtonText);
            });
            
            // Initialize button text
            updateProcessSelectedButtonText();
            
            // File upload drag and drop
            const uploadArea = document.getElementById('uploadArea');
            const fileInput = document.getElementById('fileInput');
            
            if (uploadArea && fileInput) {
                uploadArea.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadArea.classList.add('dragover');
                });
                
                uploadArea.addEventListener('dragleave', () => {
                    uploadArea.classList.remove('dragover');
                });
                
                uploadArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadArea.classList.remove('dragover');
                    const files = e.dataTransfer.files;
                    fileInput.files = files;
                    // Update upload area display when files are dropped
                    updateFileDisplay(files);
                });
                
                fileInput.addEventListener('change', (e) => {
                    // Update upload area display when files are selected
                    const files = e.target.files;
                    updateFileDisplay(files);
                });


            }
            
            // Handle popover close events
            document.addEventListener('toggle', function(event) {
                if (event.target.matches('[popover]') && event.newState === 'closed') {
                    // Reset upload area display and clear file input first
                    if (event.target.id === 'uploadModal') {
                        const instructions = event.target.querySelector('#uploadInstructions');
                        const feedback = event.target.querySelector('#uploadFeedback');
                        const fileList = event.target.querySelector('#fileList');

                        if (instructions && feedback && fileList) {
                            instructions.style.display = 'block';
                            feedback.style.display = 'none';
                            fileList.style.display = 'none';
                            fileList.innerHTML = '';
                        }

                        // Clear the file input
                        const fileInput = event.target.querySelector('#fileInput');
                        if (fileInput) {
                            fileInput.value = '';
                            // Also clear the files property for drag-and-drop files
                            if (fileInput.files) {
                                fileInput.files = null;
                            }
                        }
                    }

                    // Reset forms when popover closes
                    const form = event.target.querySelector('form');
                    if (form) {
                        form.reset();
                    }
                }
            });
            
            // Fallback for browsers without popover support
            if (!HTMLElement.prototype.hasOwnProperty('popover')) {
                // Close modals when clicking outside
                document.addEventListener('click', function(event) {
                    if (event.target.classList.contains('modal')) {
                        event.target.style.display = 'none';
                    }
                });
                
                // Close modals with Escape key
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        const modals = document.querySelectorAll('.modal');
                        modals.forEach(modal => {
                            if (modal.style.display === 'flex') {
                                modal.style.display = 'none';
                            }
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>

<?php
//
// VectorLiteAdmin Configuration File
//
// This is a sample configuration file for VectorLiteAdmin.
// To use this file:
// 1. Rename this file from vectorliteadmin.config.sample.php to vectorliteadmin.config.php
// 2. Modify the settings below as needed
// 3. The settings in this file will override the defaults in index.php
//

// Password configuration - choose one method:

// Method 1: Plain text password (not recommended for production)
$password = 'your_secure_password_here';

// Method 2: MD5 hash (better security)
// To generate: echo 'md5:' . md5('your_password');
// $password = 'md5:5d41402abc4b2a76b9719d911017c592'; // MD5 of 'hello'

// Method 3: SHA256 hash (recommended)
// To generate: echo 'sha256:' . hash('sha256', 'your_password');
// $password = 'sha256:2cf24dba4f21d4288094e9b9b6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6'; // SHA256 of 'hello'

// Method 4: Bcrypt hash (most secure, requires PHP 5.5+)
// To generate: echo 'bcrypt:' . password_hash('your_password', PASSWORD_DEFAULT);
// $password = 'bcrypt:$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

// Database directory settings
$directory = './databases';
$subdirectories = true;

// File upload settings
$max_upload_size = 50; // 50MB
$chunk_size = 1000; // Characters per chunk
$chunk_overlap = 200; // Overlap between chunks

// Embedding provider settings
$default_provider = 'openai';

// OpenAI Configuration
$embedding_providers['openai']['api_key'] = 'your-openai-api-key-here';
$embedding_providers['openai']['model'] = 'text-embedding-3-small';

// Ollama Configuration (for local models)
$embedding_providers['ollama']['endpoint'] = 'http://localhost:11434/api/embeddings';
$embedding_providers['ollama']['model'] = 'nomic-embed-text';

// LM Studio Configuration (for local models)
$embedding_providers['lmstudio']['endpoint'] = 'http://localhost:1234/v1/embeddings';
$embedding_providers['lmstudio']['api_key'] = ''; // Usually not needed for local LM Studio

// Security settings
$cookie_name = 'vectorliteadmin_auth';
$session_timeout = 3600; // 1 hour in seconds

// Theme settings
$theme = 'default';

// Advanced settings
$enable_debug = false; // Set to true for debugging (not recommended in production)
$log_queries = false; // Set to true to log database queries

?>
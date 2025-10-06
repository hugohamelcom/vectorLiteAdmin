# VectorLiteAdmin

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3.0+-blue.svg)](https://sqlite.org/)

VectorLiteAdmin is a comprehensive web-based vector database management platform inspired by phpMyAdmin but specifically designed for vector databases using SQLite. It provides an intuitive interface for managing vector databases, embedding content, performing similarity searches, and administering knowledge bases with support for multiple embedding models and APIs.

![VectorLiteAdmin Screenshot](https://github.com/hugohamelcom/vectorliteadmin/blob/1b1cc2e12bbece45a66e1d78cb3ff94d401c22c0/vectorliteadmin-database-screenshot.png)

## ✨ Features

### 🗄️ Database Management
- **Auto-discovery**: Automatically detects and manages SQLite vector databases in configured directories
- **Schema Management**: Creates and maintains vector database schemas with proper indexing
- **Multi-database Support**: Manage multiple databases simultaneously
- **Database Operations**: Create, backup, restore, and delete vector databases

### 📄 Content Management
- **File Upload**: Support for multiple file formats (PDF, TXT, DOC, DOCX, MD)
- **Drag & Drop**: Intuitive drag-and-drop interface for batch uploads
- **Content Groups**: Organize content into logical groups for better management
- **Progress Tracking**: Real-time progress indicators for upload and processing operations

### 🧠 Vector Embeddings
- **Multiple Providers**: Support for OpenAI, Google Gemini, Ollama, and LM Studio
- **Intelligent Chunking**: Smart text chunking with configurable overlap and size limits
- **Async Processing**: Background processing of embeddings with queue management
- **Batch Operations**: Process multiple documents simultaneously

### 🔍 Search & Discovery
- **Similarity Search**: Vector-based similarity search with configurable thresholds
- **Hybrid Search**: Combine vector and full-text search capabilities
- **LLM Summaries**: Generate AI-powered summaries of search results
- **Advanced Filtering**: Filter results by content groups, document types, and metadata

### 🔐 Security & Authentication
- **Password Protection**: Multiple authentication methods (plain text, MD5, SHA256, bcrypt)
- **Session Management**: Secure session handling with configurable timeouts
- **Access Control**: Role-based access control for multi-user scenarios
- **API Security**: Secure API key management and rate limiting

### 🎨 User Interface
- **Responsive Design**: Mobile-friendly interface that works on all devices
- **Modern UI**: Clean, intuitive interface inspired by phpMyAdmin
- **Real-time Updates**: Live progress indicators and status updates
- **Theme Support**: Customizable themes and color schemes

## 🚀 Quick Start

### Prerequisites

- **PHP 7.4+** with SQLite3 extension
- **Web Server** (Apache, Nginx, or built-in PHP server)
- **cURL extension** for API calls
- **Optional**: Embedding provider API keys (OpenAI, etc.)

### Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/hugohamelcom/vectorliteadmin.git
   cd vectorliteadmin
   ```

2. **Configure your web server** to serve the directory, or use PHP's built-in server:
   ```bash
   php -S localhost:8000
   ```

3. **Access VectorLiteAdmin** at `http://localhost:8000` (or your configured URL)

4. **Initial Setup:**
   - Copy `vectorliteadmin.config.sample.php` to `vectorliteadmin.config.php`
   - Configure your embedding providers and settings
   - Set up authentication (password protection)

### Basic Configuration

Edit `vectorliteadmin.config.php` to configure:

```php
// Database directory
$directory = './databases';

// Embedding providers
$embedding_providers['openai']['api_key'] = 'your-openai-api-key-here';

// Security
$password = 'your_secure_password'; // Or use hash methods
```

## 📖 Usage Guide

### Managing Databases

1. **Auto-Discovery**: VectorLiteAdmin automatically scans your database directory for SQLite files
2. **Create Database**: Use the interface to create new vector databases with proper schemas
3. **Database Info**: View statistics, schema information, and connection status

### Uploading Content

1. **File Upload**: Drag and drop files or use the file picker
2. **Content Groups**: Assign uploads to specific groups for organization
3. **Processing**: Monitor embedding progress in real-time
4. **Supported Formats**: PDF, TXT, DOCX, MD, and more

### Performing Searches

1. **Vector Search**: Enter natural language queries
2. **Advanced Options**: Configure similarity thresholds and result limits
3. **Filter Results**: Narrow down by content groups or document types
4. **AI Summaries**: Generate comprehensive answers from multiple sources

### Configuration Options

#### Embedding Providers

**OpenAI:**
```php
$embedding_providers['openai'] = [
    'api_key' => 'your-api-key',
    'model' => 'text-embedding-3-small',
    'dimensions' => 1536
];
```

**Ollama (Local):**
```php
$embedding_providers['ollama'] = [
    'endpoint' => 'http://localhost:11434/api/embeddings',
    'model' => 'nomic-embed-text',
    'dimensions' => 768
];
```

**LM Studio:**
```php
$embedding_providers['lmstudio'] = [
    'endpoint' => 'http://localhost:1234/v1/embeddings',
    'api_key' => '',
    'model' => 'text-embedding-model'
];
```

#### Authentication Methods

```php
// Plain text (not recommended for production)
$password = 'mypassword';

// SHA256 hash (recommended)
$password = 'sha256:' . hash('sha256', 'mypassword');

// Environment variable
$password = $_ENV['VECTORLITEADMIN_PASSWORD'];
```

## 🏗️ Architecture

### Database Schema

VectorLiteAdmin uses a sophisticated SQLite schema:

- **`documents`**: Stores original documents with metadata
- **`chunks`**: Text chunks for embedding processing
- **`embeddings`**: Vector embeddings with model information
- **`content_groups`**: Organization of content into logical groups
- **`embedding_queue`**: Async processing management
- **`query_cache`**: Search result caching
- **`system_settings`**: Configuration storage

### Components

- **Database Manager**: Handles database discovery, creation, and maintenance
- **Embedding Manager**: Manages multiple embedding providers and processing
- **Content Manager**: Handles file uploads, processing, and organization
- **Search Manager**: Performs vector and hybrid searches
- **Admin Manager**: System configuration and user management

## 🔧 Advanced Configuration

### Performance Tuning

```php
// Chunking settings
$chunk_size = 1000; // Characters per chunk
$chunk_overlap = 200; // Overlap between chunks

// Upload limits
$max_upload_size = 50; // MB

// Processing batch size
$embedding_batch_size = 10;
```

### Security Settings

```php
// Session configuration
$session_timeout = 3600; // 1 hour
$cookie_name = 'vectorliteadmin_auth';

// Debug mode (disable in production)
$enable_debug = false;
$log_queries = false;
```

### Custom Themes

VectorLiteAdmin supports custom themes through CSS variables and configuration options.

## 🧪 Development

### Local Development Setup

1. **Requirements:**
   - PHP 7.4+ with SQLite3, cURL, and mbstring extensions
   - Composer (optional, for additional dependencies)

2. **Development Server:**
   ```bash
   php -S localhost:8000 -t .
   ```

3. **Testing:**
   - Upload test documents
   - Test different embedding providers
   - Verify search functionality

### Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details.

#### Development Guidelines

- Follow PHP-FIG PSR standards
- Write comprehensive tests
- Document new features
- Use meaningful commit messages

## 📋 TODO / Roadmap

### High Priority
- **Fix Embedding Reliability**: Ensure all document chunks are processed and provide granular progress updates (currently some chunks are skipped and progress jumps discontinuously)

### Medium Priority
- **Improve Processing Limits**: Add intelligent chunk counting and processing limits to handle large document sets reliably
- **Enhanced Error Handling**: Add detailed logging and user feedback for failed embedding operations

### Future Enhancements
- **Performance Optimization**: Batch processing improvements and system monitoring
- **UI/UX Improvements**: Better progress indicators and status displays
- **API Expansion**: Additional REST endpoints for advanced operations

## 📊 API Reference

### REST Endpoints

VectorLiteAdmin provides RESTful API endpoints for programmatic access:

- `GET /api/databases` - List available databases
- `POST /api/upload` - Upload documents
- `POST /api/search` - Perform vector searches
- `GET /api/status` - System status and health

### Embedding API

```php
// Generate embeddings programmatically
$embeddingManager = new EmbeddingManager();
$vectors = $embeddingManager->generateEmbedding("Your text here", "openai");
```

## 🐛 Troubleshooting

### Common Issues

**"Database not found" errors:**
- Check file permissions on the databases directory
- Ensure SQLite files are not corrupted
- Verify database directory path in configuration

**Embedding provider connection failures:**
- Verify API keys and endpoints
- Check network connectivity
- Review rate limits and usage quotas

**Upload failures:**
- Check file size limits
- Verify supported file formats
- Ensure write permissions on upload directory

### Debug Mode

Enable debug mode in configuration for detailed logging:

```php
$enable_debug = true;
$log_queries = true;
```

## 📈 Performance Optimization

### Database Optimization
- Proper indexing on frequently queried columns
- WAL mode for better concurrent access
- Query result caching

### Memory Management
- Streaming file processing for large uploads
- Chunked embedding processing
- Automatic cleanup of expired cache

### Caching Strategy
- Query result caching with TTL
- Embedding result caching
- File content caching

## 🔒 Security Considerations

### Best Practices

- **Use HTTPS** in production environments
- **Strong passwords** with hashing (SHA256 or bcrypt)
- **Regular backups** of vector databases
- **Monitor access logs** for suspicious activity
- **Keep dependencies updated**

### Security Features

- SQL injection prevention through prepared statements
- XSS protection with proper output escaping
- CSRF protection for form submissions
- File upload validation and sanitization
- Secure API key storage

## 📜 License

VectorLiteAdmin is open source software licensed under the [GNU General Public License v3.0](LICENSE).

```
Copyright (C) 2024 VectorLiteAdmin Team

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
```

## 🙏 Acknowledgments

- Inspired by phpMyAdmin for database management interface
- Built with modern PHP and SQLite technologies
- Thanks to the open source community for embedding libraries and tools

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/hugohamelcom/vectorliteadmin/issues)
- **Discussions**: [GitHub Discussions](https://github.com/hugohamelcom/vectorliteadmin/discussions)
- **Documentation**: [Wiki](https://github.com/hugohamelcom/vectorliteadmin/wiki)

---

**Made with ❤️ for the vector database community**

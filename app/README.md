# Console Application

Symfony Console application skeleton using PHP 8.3 and Symfony Console Component 6+.

## Requirements

- PHP >= 8.3
- Composer

## Installation

```bash
git clone <repository-url>
cd app

# Install dependencies
composer install

# Copy environment file
cp .env.example .env
```

## Usage

```bash
# List all available commands
php bin/console list

# Display help for a specific command
php bin/console <command-name> --help

# Run via composer script
composer console -- <command-name>
```

## Project Structure

```
app/
├── bin/
│   └── console                  # Application entry point
├── config/
│   └── services.php             # Service definitions / DI config
├── src/
│   ├── Application/             # Application layer (use cases, DTOs)
│   ├── Console/
│   │   └── Command/             # Symfony Console Command classes
│   ├── Domain/                  # Domain layer (entities, value objects, interfaces)
│   └── Infrastructure/          # Infrastructure layer (adapters, implementations)
├── tests/
│   ├── Functional/              # Functional / end-to-end tests
│   ├── Integration/             # Integration tests
│   └── Unit/                    # Unit tests
├── .env.example                 # Environment variable template
├── composer.json
├── phpunit.xml
└── README.md
```

## Architecture

This project follows a **Layered / Hexagonal Architecture** approach:

| Layer | Namespace | Responsibility |
|---|---|---|
| Domain | `App\Domain` | Business rules, entities, value objects, repository interfaces |
| Application | `App\Application` | Use cases, DTOs, application services |
| Infrastructure | `App\Infrastructure` | I/O adapters: DB, HTTP, file system, external APIs |
| Console | `App\Console\Command` | CLI entry points — thin wrappers over Application services |

## Testing

```bash
# Run all tests
composer test

# Run only unit tests
composer test:unit

# Run only integration tests
composer test:integration

# Run only functional tests
composer test:functional
```

## Adding a New Command

1. Create a class in `src/Console/Command/` extending `AbstractCommand`.
2. Register it in `config/services.php`.
3. Add a corresponding test in `tests/`.

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `dev` | Runtime environment (`dev`, `prod`, `test`) |
| `APP_DEBUG` | `1` | Enable debug mode (`1` = on, `0` = off) |
| `APP_NAME` | `Console Application` | Application display name |
| `APP_VERSION` | `1.0.0` | Application version string |

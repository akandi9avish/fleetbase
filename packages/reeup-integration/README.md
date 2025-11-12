# REEUP Integration Extension

Fleetbase extension for REEUP cannabis retail platform integration.

## Features

- Custom user creation endpoint with relaxed validation for programmatic access
- Company context injection middleware for Railway deployment
- REEUP-specific IAM roles for cannabis business types
- Dedicated endpoints for backend API integration

## Endpoints

- `POST /int/v1/reeup/users` - Create user with programmatic password setting
- `GET /int/v1/reeup/users` - Query users
- `GET /int/v1/reeup/users/{id}` - Get specific user

## Installation

This extension is automatically loaded via Composer's package discovery.

## Commands

- `php artisan reeup:seed-roles` - Seed REEUP custom IAM roles

## Configuration

Configuration is loaded from `config/reeup-integration.php`

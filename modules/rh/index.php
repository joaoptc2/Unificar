<?php
/**
 * RH Hospital - Bootstrap (raiz do public_html)
 * 
 * Este arquivo redireciona para o router principal.
 * Na Hostinger, este arquivo fica em public_html/index.php
 * e o router principal fica em public_html/public/index.php
 */

// Definir BASE_PATH como a raiz do projeto
define('BASE_PATH', __DIR__);

// Detectar BASE_URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$baseDir = rtrim(dirname($scriptName), '/\\');
$baseDir = ($baseDir === '/' || $baseDir === '\\' || $baseDir === '.') ? '' : $baseDir;
define('BASE_URL', $protocol . '://' . $host . $baseDir . '/');
define('ASSET_URL', BASE_URL . 'public/');

// Incluir o router principal
require __DIR__ . '/public/index.php';

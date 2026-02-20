<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->post('slack/schedule',     'SlackController::schedule');
$routes->post('slack/reschedule',   'SlackController::reschedule');
$routes->post('slack/cancel',       'SlackController::cancel');
$routes->post('slack/schedstatus',       'SlackController::status');
$routes->post('slack/interactivity','SlackController::interactivity');

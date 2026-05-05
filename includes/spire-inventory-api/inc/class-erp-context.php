<?php
/**
 * ERP Context
 *
 * A simple value object representing the connection configuration and
 * runtime options for a particular ERP integration. Instances of this
 * class are passed to adapter methods so they can read credentials and
 * provider-specific options without tightly coupling to the WordPress
 * options API. The context also carries mapping configuration and
 * rate-limit settings so that the orchestrator can coordinate fetch
 * operations appropriately.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ERP_Context {
    /** @var string */
    public $base_url;

    /** @var string */
    public $username;

    /** @var string */
    public $password;

    /** @var array<string,mixed> */
    public $options = [];

    /** @var array<string,mixed> */
    public $mapping = [];

    /** @var array<string,mixed> */
    public $limits = [];
}
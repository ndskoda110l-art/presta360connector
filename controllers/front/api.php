<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Presta360\Connector\Api\Handler;
use Presta360\Connector\Http\JsonResponder;
use Presta360\Connector\Http\Request;

/**
 * Signed, read-only JSON endpoint:
 * POST index.php?fc=module&module=presta360connector&controller=api
 *
 * Front controller side effects (cart, cookies, geolocation, maintenance page, SSL redirect,
 * theme rendering) are skipped on purpose: the endpoint only answers machine requests.
 */
class Presta360connectorApiModuleFrontController extends ModuleFrontController
{
    /** @var int */
    private $p360Status = 500;

    /** @var array */
    private $p360Payload = [];

    public function __construct()
    {
        parent::__construct();

        $this->ajax = true;
        $this->auth = false;
        $this->guestAllowed = true;
        $this->content_only = true;
        $this->display_header = false;
        $this->display_footer = false;
        $this->ssl = Tools::usingSecureMode();

        if (isset($this->context->cookie) && method_exists($this->context->cookie, 'disallowWriting')) {
            $this->context->cookie->disallowWriting();
        }
    }

    public function init()
    {
        // Run only the base controller init; FrontController::init() would create carts,
        // write cookies, apply geolocation rules and render the maintenance page.
        Controller::init();
    }

    public function checkAccess()
    {
        return true;
    }

    public function setMedia()
    {
    }

    public function initHeader()
    {
    }

    public function initContent()
    {
    }

    public function initFooter()
    {
    }

    protected function displayMaintenancePage()
    {
    }

    protected function sslRedirection()
    {
    }

    public function postProcess()
    {
        $handler = new Handler($this->module);
        $result = $handler->handle(Request::fromGlobals());
        $this->p360Status = $result[0];
        $this->p360Payload = $result[1];
    }

    public function displayAjax()
    {
        JsonResponder::send($this->p360Status, $this->p360Payload);
    }
}

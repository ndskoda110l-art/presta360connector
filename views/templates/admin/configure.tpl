{**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<div class="panel">
  <div class="panel-heading">{$p360.t.connection|escape:'htmlall':'UTF-8'}</div>
  <p>{$p360.t.copy_both|escape:'htmlall':'UTF-8'}</p>
  <div class="form-group">
    <label>{$p360.t.endpoint|escape:'htmlall':'UTF-8'}</label>
    <input type="text" class="form-control" readonly value="{$p360.endpoint|escape:'htmlall':'UTF-8'}" onclick="this.select()">
  </div>
  <div class="form-group">
    <label>{$p360.t.secret|escape:'htmlall':'UTF-8'}</label>
    {if $p360.secret}
      <input type="text" class="form-control" readonly value="{$p360.secret|escape:'htmlall':'UTF-8'}" onclick="this.select()">
      <p class="help-block"><strong>{$p360.t.shown_once|escape:'htmlall':'UTF-8'}</strong></p>
    {else}
      <p class="form-control-static">{$p360.t.hidden|escape:'htmlall':'UTF-8'}</p>
    {/if}
  </div>
  <form method="post" action="{$p360.action|escape:'htmlall':'UTF-8'}" data-confirm="{$p360.t.confirm|escape:'htmlall':'UTF-8'}" onsubmit="return confirm(this.getAttribute('data-confirm'));">
    <p class="help-block">{$p360.t.regenerate_help|escape:'htmlall':'UTF-8'}</p>
    <button type="submit" name="submitP360Regenerate" value="1" class="btn btn-default">{$p360.t.regenerate|escape:'htmlall':'UTF-8'}</button>
  </form>
</div>

<div class="panel">
  <div class="panel-heading">{$p360.t.status|escape:'htmlall':'UTF-8'}</div>
  <table class="table">
    <tbody>
      <tr><td>{$p360.t.module_version|escape:'htmlall':'UTF-8'}</td><td>{$p360.module_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><td>{$p360.t.contract|escape:'htmlall':'UTF-8'}</td><td>{$p360.contract|intval}</td></tr>
      <tr><td>{$p360.t.ps_version|escape:'htmlall':'UTF-8'}</td><td>{$p360.ps_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><td>{$p360.t.php_version|escape:'htmlall':'UTF-8'}</td><td>{$p360.php_version|escape:'htmlall':'UTF-8'}</td></tr>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-heading">{$p360.t.what|escape:'htmlall':'UTF-8'}</div>
  <p>{$p360.t.what_queries|escape:'htmlall':'UTF-8'}</p>
  <p>{$p360.t.what_safety|escape:'htmlall':'UTF-8'}</p>
  <p>{$p360.t.source|escape:'htmlall':'UTF-8'}: {$p360.source_url|escape:'htmlall':'UTF-8'}</p>
</div>

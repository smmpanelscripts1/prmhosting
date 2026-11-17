@php
  $lib = '/blueprint_extensions/prmhosting/lib';
  if (!is_dir($lib)) { $lib = base_path('.blueprint/extensions/prmhosting/lib'); }
  if (!is_dir($lib)) { $lib = dirname(__DIR__) . '/prmhosting/lib'; }
  require_once rtrim($lib, '/').'/Settings.php';
  $cfg = \PrmHosting\Settings::get();
@endphp
<div class="prmhosting-admin">
  <div class="card">
    <div class="card-header"><h4 class="mb-0">PRM Hosting</h4></div>
    <div class="card-body">
      <p class="text-muted">Nginx egg varsayılanları ve paket şablonları. Next panel <code>/api/hosting</code> bu değerleri kullanır.</p>
      <form method="post" action="/extensions/prmhosting/admin/save">
        @csrf
        <div class="row">
          <div class="col-md-3 mb-2">
            <label class="form-label">Nest ID</label>
            <input class="form-control" name="nest_id" type="number" value="{{ (int) $cfg['nest_id'] }}">
          </div>
          <div class="col-md-3 mb-2">
            <label class="form-label">Egg ID</label>
            <input class="form-control" name="egg_id" type="number" value="{{ (int) $cfg['egg_id'] }}">
          </div>
          <div class="col-md-3 mb-2">
            <label class="form-label">Node ID</label>
            <input class="form-control" name="node_id" type="number" value="{{ (int) $cfg['node_id'] }}">
          </div>
          <div class="col-md-3 mb-2">
            <label class="form-label">Owner ID</label>
            <input class="form-control" name="owner_id" type="number" value="{{ (int) $cfg['owner_id'] }}">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Docker image</label>
          <input class="form-control" name="docker_image" value="{{ $cfg['docker_image'] }}">
        </div>
        <div class="mb-2">
          <label class="form-label">Startup</label>
          <input class="form-control" name="startup" value="{{ $cfg['startup'] }}">
        </div>
        <div class="mb-3">
          <label class="form-label">Packages JSON</label>
          <textarea class="form-control" name="packages_json" rows="14">{{ json_encode($cfg['packages'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        </div>
        <button class="btn btn-primary" type="submit">Kaydet</button>
      </form>
    </div>
  </div>
</div>

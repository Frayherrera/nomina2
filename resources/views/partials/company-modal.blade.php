<div class="modal-backdrop {{ $errors->company->any() ? 'show' : '' }}" id="company-modal" onclick="closeCompanyModal()">
    <form class="modal" method="POST" action="{{ route('companies.store') }}" onclick="event.stopPropagation()">
        @csrf
        <div class="modal-head">
            <div>
                <h2>Registrar empresa</h2>
                <p>Se creará un espacio de trabajo independiente.</p>
            </div>
            <button class="icon-btn" type="button" onclick="closeCompanyModal()" aria-label="Cerrar"><x-icon name="x"/></button>
        </div>
        <label>Nombre de la empresa
            <input id="company-name" name="name" value="{{ old('name') }}" placeholder="Ej. Empresa ABC S.A.S." required maxlength="150"
                   oninput="document.getElementById('company-submit').disabled = !this.value.trim()">
        </label>
        @error('name', 'company')<div class="field-error">{{ $message }}</div>@enderror
        <label>NIT o identificación (opcional)
            <input name="tax_id" value="{{ old('tax_id') }}" placeholder="900.000.000-1" maxlength="50">
        </label>
        <div class="modal-actions">
            <button class="btn ghost" type="button" onclick="closeCompanyModal()">Cancelar</button>
            <button class="btn primary" id="company-submit" type="submit" {{ old('name') ? '' : 'disabled' }}>Crear empresa</button>
        </div>
    </form>
</div>

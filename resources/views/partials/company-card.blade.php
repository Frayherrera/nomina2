<a class="company-card" href="{{ route('companies.show', $company) }}">
    <div class="company-card-top">
        <div class="company-initial">{{ $company->initial() }}</div>
        <span class="badge green">Activa</span>
    </div>
    <h3>{{ $company->name }}</h3>
    <p>{{ $subtitle }}</p>
    <div class="company-card-cta"><span>{{ $cta }}</span><x-icon name="arrow-right" :size="17"/></div>
</a>

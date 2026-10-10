<x-field name="code" label="Account code" :value="$definition->code" :required="true"/>
<p class="subtext">Leading zeros and punctuation are preserved. Codes differing only in ASCII letter case or outer spaces count as the same code.</p>
<x-field name="name" label="Account name" :value="$definition->name" :required="true"/>
<x-field name="classification" label="Classification" type="select" :value="$definition->classification" :options="array_combine(\App\Services\Accounting\ChartOfAccounts::CLASSIFICATIONS,\App\Services\Accounting\ChartOfAccounts::CLASSIFICATIONS)" :required="true"/>

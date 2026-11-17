{{-- Type picker. $target: JS expression of an object with {kind, raw}; $withNone: 'true'|'false' --}}
<select class="kind-select" @change="setKind({{ $target }}, $event.target.value)">
    <template x-for="g in kindOptions({{ $withNone }}, {{ $target }}.kind)" :key="g.label">
        <optgroup :label="g.label || 'Body'">
            <template x-for="k in g.options" :key="k">
                <option :value="k" :selected="k === {{ $target }}.kind" x-text="kindLabel(k)"></option>
            </template>
        </optgroup>
    </template>
</select>

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
    $statePath = $getStatePath();
    $defaultLocation = $getDefaultLocation();
    $zoom = $getZoom();
    $mapHeight = '400px';
    @endphp

    @once
    {{-- Leaflet CSS iš CDN --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    {{-- Leaflet JS iš CDN --}}
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @endonce

    <div x-data="{
        statePath: '{{ $statePath }}',
        defaultLocation: {{ json_encode($defaultLocation) }}, 
        zoom: {{ $zoom }},
        state: $wire.entangle('{{ $statePath }}'),
        map: null,
        marker: null,

        init() {
            // Palaukiam, kol Leaflet biblioteka bus visiškai užkrauta
            const waitForLeaflet = setInterval(() => {
                if (typeof L !== 'undefined') {
                    clearInterval(waitForLeaflet);
                    this.initMap();
                }
            }, 50);
            
            // NAUJA: Klausomės Livewire įvykio, dispečiuoto iš address-autocomplete.
            // Kai adresas pasirinktas, atnaujiname šios komponentės 'state'.
            this.$wire.on('addressSelected', (addressData) => {
                const data = addressData[0]; // Filament/Livewire kartais įdeda duomenis į masyvą
                
                if (data.latitude && data.longitude) {
                    this.updateStateAndMap({ 
                        latitude: data.latitude, 
                        longitude: data.longitude 
                    });
                }
            });
        },

        initMap() {
            if (!this.$refs.mapContainer) {
                console.error('❌ Map container not found.');
                return;
            }

            // NAUJA: Pataisytas pradinis state nustatymas
            const initialLat = this.state?.latitude || this.defaultLocation[0];
            const initialLng = this.state?.longitude || this.defaultLocation[1];

            // Užtikrinam, kad state būtų nustatytas, net jei jis tuščias
            if (!this.state || !this.state.latitude) {
                this.state = {
                    latitude: initialLat,
                    longitude: initialLng
                };
            }

            try {
                // Sukuriamas Leaflet žemėlapis
                this.map = L.map(this.$refs.mapContainer, {
                    center: [initialLat, initialLng],
                    zoom: this.zoom
                });

                // OpenStreetMap tiles
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap'
                }).addTo(this.map);

                // Marker su galimybe vilkti
                this.marker = L.marker([initialLat, initialLng], {
                    draggable: true
                }).addTo(this.map);

                // Atnaujinam 'state' kintamąjį pabaigus vilkti markerį
                this.marker.on('dragend', (e) => {
                    const pos = e.target.getLatLng();
                    this.updateStateAndMap({ 
                        latitude: pos.lat, 
                        longitude: pos.lng 
                    }, false); // Vilkimas atnaujina state, bet nejudina žemėlapio
                });
                
                // Stebim 'state' pokyčius, kad atnaujintume markerio poziciją ir perstumtume žemėlapį
                this.$watch('state.latitude', (newVal, oldVal) => this.onStateChange(newVal, oldVal));
                this.$watch('state.longitude', (newVal, oldVal) => this.onStateChange(newVal, oldVal));

                // Priverstinai atnaujinti žemėlapio dydį (svarbu, kai jis yra modale ar tūle)
                setTimeout(() => {
                    this.map.invalidateSize();
                }, 100);

            } catch (error) {
                console.error('❌ Error initializing map:', error);
            }
        },
        
        // Naujas bendrasis atnaujinimo metodas
        updateStateAndMap(newCoords, setView = true) {
            this.state = {
                latitude: newCoords.latitude,
                longitude: newCoords.longitude,
            };

            if (this.marker) {
                const pos = [newCoords.latitude, newCoords.longitude];
                this.marker.setLatLng(pos);
                if (setView && this.map) {
                    this.map.setView(pos, this.map.getZoom(), {animate: true});
                }
            }
        },

        // NAUJA: Apsaugos nuo begalinio atnaujinimo, stebint state
        onStateChange(newVal, oldVal) {
            if (newVal === oldVal || !this.state?.latitude || !this.state?.longitude || !this.marker) {
                return;
            }

            const currentMarkerPos = this.marker.getLatLng();
            const newStatePos = { lat: this.state.latitude, lng: this.state.longitude };
            
            // Tikriname, ar žymeklis jau yra toje pačioje vietoje (išvengiama loop'o)
            if (currentMarkerPos.lat.toFixed(6) !== newStatePos.lat.toFixed(6) || 
                currentMarkerPos.lng.toFixed(6) !== newStatePos.lng.toFixed(6)) 
            {
                this.updateStateAndMap(newStatePos, true);
            }
        }
    }" class="space-y-4">

        {{-- Input kairėje (50%) ir žemėlapis dešinėje (50%) --}}
        <div class="col-span-full">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start !w-full !max-w-none">
                {{-- Kairė pusė – adresų įvedimas --}}
                <div class="relative z-50">
                    {{-- SVARBU: perduodame statePath, kad Adresų komponente dispečiuotų atgal į Filament --}}
                    @livewire('address-autocomplete', ['statePath' => $statePath])
                </div>

                {{-- Dešinė pusė – žemėlapis --}}
                <div x-ref="mapContainer" wire:ignore
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 shadow-inner z-10"
                    style="height: {{ $mapHeight }}; min-height: {{ $mapHeight }};">
                </div>
            </div>
        </div>

        {{-- Koordinačių rodymas --}}
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-700 dark:text-gray-300">
            <div>
                <span class="font-semibold">Platuma (Lat):</span>
                <span x-text="state?.latitude?.toFixed(8) || '-'"></span>
            </div>
            <div>
                <span class="font-semibold">Ilguma (Lng):</span>
                <span x-text="state?.longitude?.toFixed(8) || '-'"></span>
            </div>
        </div>
    </div>
</x-dynamic-component>
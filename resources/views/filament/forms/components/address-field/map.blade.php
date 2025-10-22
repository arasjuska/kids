@php
    $latitude = (float) ($coordinates['latitude'] ?? 54.8985);
    $longitude = (float) ($coordinates['longitude'] ?? 23.9036);
    $height = $height ?? 360;
@endphp

<div
    x-data="addressFieldMapComponent({
        statePath: '{{ $statePath }}',
        height: {{ $height }},
        initialLatitude: {{ $latitude }},
        initialLongitude: {{ $longitude }},
    })"
    x-init="init()"
    x-on:address-field::focus-map.window="focusMarker($event.detail)"
    x-on:address-field::select.window="handleExternalSelection($event.detail)"
    wire:ignore
    class="fi-fo-address-map relative z-0"
>
    <div
        x-ref="map"
        class="w-full rounded-lg border border-gray-200 dark:border-gray-700"
        style="height: clamp(240px, 40vh, {{ $height }}px);"
    ></div>
    <template x-if="mapUnavailable">
        <div class="w-full h-full rounded-lg border border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-900/20 dark:text-amber-200 flex items-center justify-center p-4">
            <div class="text-center space-y-1">
                <div class="text-sm font-medium">Žemėlapio biblioteka nepasiekiama (be tinklo / CDN).</div>
                <div class="text-xs opacity-80">
                    Platuma:
                    <span x-text="(() => { const c = readCoordinates(); return (c.lat && !Number.isNaN(c.lat) && c.lat.toFixed) ? c.lat.toFixed(6) : c.lat; })()"></span>,
                    Ilguma:
                    <span x-text="(() => { const c = readCoordinates(); return (c.lng && !Number.isNaN(c.lng) && c.lng.toFixed) ? c.lng.toFixed(6) : c.lng; })()"></span>
                </div>
                <div class="text-xs opacity-80" x-show="state?.confidence_score != null">
                    Patikimumas:
                    <span x-text="Math.round((Number(state.confidence_score) || 0) * 100) + '%'"
                          :class="confidenceBadgeClass()"></span>
                </div>
            </div>
        </div>
    </template>

    <!-- Confidence badge overlay when map is available and address confirmed -->
    <div
        x-show="!mapUnavailable && (state?.current_state === 'confirmed') && (state?.confidence_score != null)"
        class="absolute right-2 top-2 z-[401] pointer-events-none"
        x-cloak
    >
        <span :class="confidenceBadgeClass()">
            Patikimumas: <span x-text="Math.round((Number(state.confidence_score) || 0) * 100) + '%' "></span>
        </span>
    </div>

</div>

@once
    @push('scripts')
        <script>
            window.addressFieldMapComponent = function ({ statePath, height, initialLatitude, initialLongitude }) {
                return {
                    statePath,
                    map: null,
                    marker: null,
                    state: null,
                    mapUnavailable: false,
                    L: null,
                    leafletPromise: null,
                confidenceBadgeClass() {
                    const v = Number(this.state?.confidence_score ?? 0);
                        const level = (v >= 0.95) ? 'success' : (v >= 0.6 ? 'warning' : 'danger');
                        const base = 'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';
                        switch (level) {
                            case 'success':
                                return `${base} bg-green-50 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-200 dark:border-green-500/30`;
                            case 'warning':
                                return `${base} bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:border-amber-500/30`;
                            default:
                                return `${base} bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-200 dark:border-red-500/30`;
                        }
                },

                parseNumber(value) {
                    const parsed = Number.parseFloat(value);
                    return Number.isFinite(parsed) ? parsed : NaN;
                },

                roundToSix(value) {
                    return Math.round(value * 1e6) / 1e6;
                },

                updateManualFields(suggestion) {
                    if (! suggestion) {
                        return;
                    }

                    const manualFields = {
                        formatted_address: suggestion.formatted_address ?? null,
                        street_name: suggestion.street_name ?? null,
                        street_number: suggestion.street_number ?? null,
                        city: suggestion.city ?? null,
                        state: suggestion.state ?? null,
                        postal_code: suggestion.postal_code ?? null,
                        country: suggestion.country ?? 'Lietuva',
                        country_code: suggestion.country_code ?? 'LT',
                    };

                    Object.entries(manualFields).forEach(([key, value]) => {
                        this.$wire.set(`${this.statePath}.manual_fields.${key}`, value);
                        if (this.state?.manual_fields) {
                            this.state.manual_fields[key] = value;
                        }
                    });
                },

                init() {
                    this.state = this.$wire.entangle(this.statePath).live;

                    this.loadLeaflet()
                        .then((L) => {
                            if (! L) {
                                this.mapUnavailable = true;
                                return;
                            }

                            this.L = L;
                            this.buildMap();
                            this.watchForStateChanges();
                            window.addEventListener('resize', () => {
                                if (! this.map) {
                                    return;
                                }
                                window.requestAnimationFrame(() => this.map.invalidateSize());
                            });
                        })
                        .catch(() => {
                            this.mapUnavailable = true;
                        });
                    },

                    loadLeaflet() {
                        if (typeof window === 'undefined') {
                            return Promise.resolve(null);
                        }

                        return Promise.resolve(window.L ?? null);
                    },

                    buildMap() {
                        if (! this.$refs.map) {
                            return;
                        }

                        // Defer to ensure the container has a height before Leaflet initialises.
                        window.requestAnimationFrame(() => {
                            const mapElement = this.$refs.map;
                            if (! mapElement || mapElement.dataset.mapInitialised === 'true') {
                                return;
                            }

                            const L = this.L ?? window.L;
                            if (! L) {
                                this.mapUnavailable = true;
                                return;
                            }

                            const coords = this.readCoordinates();

                            this.map = L.map(mapElement).setView([coords.lat, coords.lng], 17);
                            this.renderTileLayer(L);

                            this.marker = L.marker([coords.lat, coords.lng], {
                                draggable: true,
                            }).addTo(this.map);

                            this.marker.on('dragend', (event) => {
                                const position = event.target.getLatLng();
                                this.commitCoordinates(position.lat, position.lng);
                            });

                            mapElement.dataset.mapInitialised = 'true';

                            window.requestAnimationFrame(() => this.map.invalidateSize());
                        });
                    },

                    renderTileLayer(L) {
                        if (! this.map) {
                            return;
                        }

                        const baseLayers = [
                            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                        ];

                        const options = {
                            maxZoom: 19,
                            attribution: '&copy; OpenStreetMap contributors',
                        };

                        let layerAdded = false;

                        baseLayers.forEach((url, index) => {
                            if (layerAdded) {
                                return;
                            }

                            try {
                                const layer = L.tileLayer(url, options);
                                layer.addTo(this.map);
                                layerAdded = true;

                                // Immediately remove non-first layers from the DOM to avoid duplication,
                                // ensuring only one set of tiles stays visible.
                                if (index > 0) {
                                    layer.remove();
                                }
                            } catch (error) {
                                console.warn('[Leaflet] Failed to add tile layer for URL:', url, error);
                            }
                        });
                    },

                    watchForStateChanges() {
                        this.$watch('state.coordinates', (value) => {
                            if (! value) {
                                return;
                            }

                            const lat = parseFloat(value.latitude ?? initialLatitude);
                            const lng = parseFloat(value.longitude ?? initialLongitude);

                            if (Number.isNaN(lat) || Number.isNaN(lng)) {
                                return;
                            }

                            this.updateMarkerPosition(lat, lng, { pan: true });
                        });
                    },

                    focusMarker(detail) {
                        if (! detail) {
                            return;
                        }

                        const lat = parseFloat(detail.lat);
                        const lng = parseFloat(detail.lng);
                        if (Number.isNaN(lat) || Number.isNaN(lng)) {
                            return;
                        }

                        this.updateMarkerPosition(lat, lng, { pan: true });
                    },

                    handleExternalSelection(detail) {
                        if (! detail) {
                            return;
                        }

                        const suggestion = detail.suggestion ?? null;
                        const placeId = detail.place_id ?? suggestion?.place_id ?? null;

                        if (placeId) {
                            const placeIdString = String(placeId);
                            this.$wire.set(this.statePath + '.selected_place_id', placeIdString);
                            if (this.state) {
                                this.state.selected_place_id = placeIdString;
                            }
                        }

                        if (suggestion) {
                            this.$wire.set(this.statePath + '.selected_suggestion', suggestion);
                            this.$wire.set(this.statePath + '.suggestions', [suggestion]);
                            this.$wire.set(this.statePath + '.raw_api_payload', suggestion);
                            if (this.state) {
                                this.state.selected_suggestion = suggestion;
                                this.state.suggestions = [suggestion];
                            }

                            this.updateManualFields(suggestion);

                            const confidence = this.parseNumber(suggestion.confidence ?? detail.confidence ?? 0) || 0;
                            const confidenceRounded = Math.round(confidence * 100) / 100;
                            this.$wire.set(`${this.statePath}.confidence_score`, confidenceRounded);
                            if (this.state) {
                                this.state.confidence_score = confidenceRounded;
                            }

                            const addressType = confidence >= 0.95
                                ? 'verified'
                                : (confidence >= 0.6 ? 'low_confidence' : 'unverified');
                            this.$wire.set(`${this.statePath}.address_type`, addressType);
                            if (this.state) {
                                this.state.address_type = addressType;
                            }
                        }

                        const lat = this.parseNumber(detail.lat ?? suggestion?.latitude ?? suggestion?.lat ?? suggestion?.latLng?.[0]);
                        const lng = this.parseNumber(detail.lng ?? suggestion?.longitude ?? suggestion?.lng ?? suggestion?.latLng?.[1]);

                        if (! Number.isNaN(lat) && ! Number.isNaN(lng)) {
                            const latRounded = this.roundToSix(lat);
                            const lngRounded = this.roundToSix(lng);
                            this.$wire.set(this.statePath + '.coordinates.latitude', latRounded);
                            this.$wire.set(this.statePath + '.coordinates.longitude', lngRounded);
                            if (this.state?.coordinates) {
                                this.state.coordinates.latitude = latRounded;
                                this.state.coordinates.longitude = lngRounded;
                            }
                            this.focusMarker({ lat: latRounded, lng: lngRounded });
                        }

                        this.$wire.set(this.statePath + '.current_state', 'confirmed');
                        if (this.state) {
                            this.state.current_state = 'confirmed';
                        }
                        // Clear validation errors once selection is done
                        this.$wire.set(`${this.statePath}.messages.errors`, []);
                        if (this.state?.messages) {
                            this.state.messages.errors = [];
                        }
                    },

                    readCoordinates() {
                        const lat = parseFloat(this.state?.coordinates?.latitude ?? initialLatitude);
                        const lng = parseFloat(this.state?.coordinates?.longitude ?? initialLongitude);

                        return {
                            lat: Number.isNaN(lat) ? initialLatitude : lat,
                            lng: Number.isNaN(lng) ? initialLongitude : lng,
                        };
                    },

                    commitCoordinates(lat, lng) {
                        if (! this.state) {
                            return;
                        }

                        const latRounded = Math.round(parseFloat(lat) * 1e6) / 1e6;
                        const lngRounded = Math.round(parseFloat(lng) * 1e6) / 1e6;

                        this.state.coordinates = {
                            latitude: latRounded,
                            longitude: lngRounded,
                        };

                        this.state.control ??= {};
                        this.state.control.coordinates_sync_token = Date.now();
                    },

                    updateMarkerPosition(lat, lng, { pan = false } = {}) {
                        if (! this.marker || ! this.map) {
                            return;
                        }

                        const newLatLng = [lat, lng];
                        this.marker.setLatLng(newLatLng);

                        if (pan) {
                            window.requestAnimationFrame(() => this.map.panTo(newLatLng, { animate: true }));
                        }
                    },
                };
            };
        </script>
    @endpush
@endonce

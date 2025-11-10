import '../../css/components/quill.css';
import '../../css/components/filepond.css';
import '../../css/components/apexcharts.css';
import '../../css/components/tableGrid.css';
import '../../css/components/swiper.css';

import Quill from 'quill';
import * as FilePond from 'filepond';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';
import ApexCharts from 'apexcharts';
import * as Gridjs from 'gridjs';
import Swiper from 'swiper/bundle';

if (typeof FilePond.registerPlugin === 'function') {
    FilePond.registerPlugin(FilePondPluginImagePreview);
}

const preloaded = window.__preloadedEntryModules = window.__preloadedEntryModules ?? {};

preloaded['quill.entry'] = {
    loadQuill: async () => Quill,
};

preloaded['filepond.entry'] = {
    loadFilePond: async () => FilePond,
};

preloaded['apexcharts.entry'] = {
    loadApex: async () => ApexCharts,
};

preloaded['gridjs.entry'] = {
    loadGridjs: async () => Gridjs,
};

preloaded['swiper.entry'] = {
    loadSwiper: async () => Swiper,
};

window.Quill = Quill;
window.FilePond = FilePond;
window.ApexCharts = ApexCharts;
window.Gridjs = Gridjs;
window.Swiper = Swiper;

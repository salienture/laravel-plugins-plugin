<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Plugin {
    slug: string;
    name: string;
    description: string;
    version: string;
    author: string;
    authorUri?: string | null;
    pluginUri?: string | null;
    license: string | null;
    textDomain?: string | null;
    requiresPhp?: string | null;
    requiresLaravel?: string | null;
    hasUpdateSource: boolean;
    isActive: boolean;
    autoUpdate: boolean | null;
    latestVersion?: string | null;
    changelog?: string | null;
    updateAvailable: boolean;
    lastCheckedAt?: string | null;
}

interface TrashItem {
    folder: string;
    slug: string;
    name: string;
    version?: string | null;
    trashedAt: string;
}

const props = defineProps<{
    canManagePlugins: boolean;
    plugins: Plugin[];
    autoUpdateGloballyEnabled: boolean;
    updatesPending: number;
    maxUploadKb: number;
    trash: TrashItem[];
}>();

const page = usePage();
const user = computed(() => (page.props as any).auth?.user);

const query = ref('');
const filter = ref<'all' | 'active' | 'inactive' | 'updates'>('all');
const uploading = ref(false);
const error = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const counts = computed(() => ({
    all: props.plugins.length,
    active: props.plugins.filter((p) => p.isActive).length,
    inactive: props.plugins.filter((p) => !p.isActive).length,
    updates: props.plugins.filter((p) => p.updateAvailable).length,
}));

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.plugins.filter((plugin) => {
        if (filter.value === 'active' && !plugin.isActive) return false;
        if (filter.value === 'inactive' && plugin.isActive) return false;
        if (filter.value === 'updates' && !plugin.updateAvailable) return false;

        if (needle.length === 0) return true;

        const haystack = [
            plugin.name,
            plugin.slug,
            plugin.description,
            plugin.author,
        ].filter(Boolean).join(' ').toLowerCase();

        return haystack.includes(needle);
    });
});

function toggle(plugin: Plugin) {
    router.post(`/plugins/${plugin.slug}/toggle`);
}

function cycleAutoUpdate(plugin: Plugin) {
    router.post(`/plugins/${plugin.slug}/auto-update`);
}

function update(plugin: Plugin) {
    router.post(`/plugins/${plugin.slug}/update`);
}

function trash(plugin: Plugin) {
    if (!window.confirm(`Move "${plugin.name}" to trash?`)) return;
    router.post(`/plugins/${plugin.slug}/trash`);
}

function restore(item: TrashItem) {
    router.post(`/plugins/trash/${item.folder}/restore`);
}

function destroyPermanently(item: TrashItem) {
    if (!window.confirm(`Permanently delete "${item.name}" and its tables?`)) return;
    router.delete(`/plugins/trash/${item.folder}`);
}

function emptyTrash() {
    if (!window.confirm('Permanently delete every trashed plugin?')) return;
    router.post('/plugins/trash/empty');
}

function checkUpdates() {
    router.post('/plugins/check-updates');
}

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const form = new FormData();
    form.append('plugin', file);

    uploading.value = true;
    error.value = null;

    router.post('/plugins/upload', form, {
        forceFormData: true,
        onError: (errors: any) => {
            error.value = errors.plugin ?? 'Upload failed.';
            uploading.value = false;
        },
        onFinish: () => {
            uploading.value = false;
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

function autoUpdateLabel(value: boolean | null): string {
    if (value === true) return 'On';
    if (value === false) return 'Off';
    return props.autoUpdateGloballyEnabled ? 'Default (on)' : 'Default (off)';
}
</script>

<template>
    <div v-if="!canManagePlugins" class="p-8 text-center text-gray-500">
        You do not have permission to manage plugins.
    </div>

    <div v-else class="p-6">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Plugins</h1>
                <p class="text-sm text-gray-500">
                    Manage plugins for {{ user?.name ?? 'this site' }}.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    @click="checkUpdates"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                >
                    Check for updates
                </button>
                <button
                    @click="fileInput?.click()"
                    :disabled="uploading"
                    class="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    {{ uploading ? 'Uploading…' : 'Upload plugin' }}
                </button>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".zip"
                    class="hidden"
                    @change="onFileSelected"
                />
            </div>
        </div>

        <div v-if="error" class="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">
            {{ error }}
        </div>

        <div
            v-if="updatesPending > 0"
            class="mb-4 rounded-md bg-yellow-50 px-4 py-2 text-sm text-yellow-800"
        >
            {{ updatesPending }} update(s) available.
        </div>

        <div class="mb-6 flex flex-wrap items-center gap-3">
            <input
                v-model="query"
                type="search"
                placeholder="Search by name, slug, description or author…"
                class="w-72 rounded-md border border-gray-300 px-3 py-1.5 text-sm"
            />

            <div class="flex gap-1">
                <button
                    v-for="[key, label] in ([
                        ['all', 'All'],
                        ['active', 'Active'],
                        ['inactive', 'Inactive'],
                        ['updates', 'Updates'],
                    ] as [any, string][])"
                    :key="key"
                    @click="filter = key"
                    :class="filter === key
                        ? 'bg-gray-900 text-white'
                        : 'border border-gray-300 hover:bg-gray-50'"
                    class="rounded-md px-3 py-1.5 text-sm"
                >
                    {{ label }}
                    <span class="ml-1 text-xs opacity-70">{{ counts[key as any] }}</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="plugin in filtered"
                :key="plugin.slug"
                class="rounded-lg border border-gray-200 p-4 shadow-sm"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <h3 class="font-semibold">{{ plugin.name }}</h3>
                        <p class="text-xs text-gray-500">{{ plugin.slug }}</p>
                    </div>
                    <span
                        :class="plugin.isActive
                            ? 'bg-green-100 text-green-700'
                            : 'bg-gray-100 text-gray-600'"
                        class="rounded-full px-2 py-0.5 text-xs"
                    >
                        {{ plugin.isActive ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <p class="mb-3 line-clamp-2 text-sm text-gray-600">
                    {{ plugin.description }}
                </p>

                <div
                    v-if="plugin.updateAvailable && plugin.latestVersion"
                    class="mb-3 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800"
                >
                    Version {{ plugin.latestVersion }} available ({{ plugin.version }} installed)
                </div>

                <div class="mb-4 grid grid-cols-2 gap-1 text-xs text-gray-500">
                    <span>Version: {{ plugin.version }}</span>
                    <span>Author: {{ plugin.author }}</span>
                    <span>License: {{ plugin.license ?? '—' }}</span>
                    <span>Requires PHP: {{ plugin.requiresPhp ?? '—' }}</span>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                    <button
                        @click="toggle(plugin)"
                        :class="plugin.isActive
                            ? 'border border-gray-300 hover:bg-gray-50'
                            : 'bg-gray-900 text-white hover:bg-gray-800'"
                        class="rounded-md px-3 py-1.5 text-sm"
                    >
                        {{ plugin.isActive ? 'Deactivate' : 'Activate' }}
                    </button>

                    <button
                        v-if="plugin.updateAvailable"
                        @click="update(plugin)"
                        class="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700"
                    >
                        Update now
                    </button>

                    <button
                        @click="cycleAutoUpdate(plugin)"
                        title="Cycle auto-update preference"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                    >
                        Auto: {{ autoUpdateLabel(plugin.autoUpdate) }}
                    </button>

                    <button
                        v-if="!plugin.isActive"
                        @click="trash(plugin)"
                        class="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                    >
                        Trash
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="filtered.length === 0"
            class="rounded-lg border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500"
        >
            No plugins match your current search or filter.
        </div>

        <div v-if="trash.length > 0" class="mt-10">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Trash</h2>
                <button
                    @click="emptyTrash"
                    class="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                >
                    Empty trash
                </button>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Version</th>
                            <th class="px-4 py-2">Trashed at</th>
                            <th class="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in trash" :key="item.folder" class="border-t border-gray-100">
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ item.name }}</span>
                                <span class="ml-2 text-xs text-gray-400">{{ item.slug }}</span>
                            </td>
                            <td class="px-4 py-2">{{ item.version ?? '—' }}</td>
                            <td class="px-4 py-2">{{ item.trashedAt }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    @click="restore(item)"
                                    class="mr-2 rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50"
                                >
                                    Restore
                                </button>
                                <button
                                    @click="destroyPermanently(item)"
                                    class="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8 text-center text-xs text-gray-400">
            {{ counts.all }} plugin(s) installed · max upload {{ maxUploadKb }} KB
        </div>
    </div>
</template>

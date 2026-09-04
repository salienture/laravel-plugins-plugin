import { router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

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

interface Props {
    canManagePlugins: boolean;
    plugins: Plugin[];
    autoUpdateGloballyEnabled: boolean;
    updatesPending: number;
    maxUploadKb: number;
    trash: TrashItem[];
}

type Filter = 'all' | 'active' | 'inactive' | 'updates';

const asArray = <T,>(value: T[] | undefined | null): T[] =>
    Array.isArray(value) ? value : [];

export default function PluginsIndex({
    canManagePlugins,
    plugins,
    autoUpdateGloballyEnabled,
    updatesPending,
    maxUploadKb,
    trash,
}: Props) {
    const { auth } = usePage().props as { auth: { user?: { name?: string } } };
    const fileInput = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const pluginList = asArray(plugins);
    const trashList = asArray(trash);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return pluginList.filter((plugin) => {
            if (filter === 'active' && !plugin.isActive) return false;
            if (filter === 'inactive' && plugin.isActive) return false;
            if (filter === 'updates' && !plugin.updateAvailable) return false;

            if (needle.length === 0) return true;

            const haystack = [
                plugin.name,
                plugin.slug,
                plugin.description,
                plugin.author,
            ].filter(Boolean).join(' ').toLowerCase();

            return haystack.includes(needle);
        });
    }, [pluginList, query, filter]);

    const counts = useMemo(() => ({
        all: pluginList.length,
        active: pluginList.filter((p) => p.isActive).length,
        inactive: pluginList.filter((p) => !p.isActive).length,
        updates: pluginList.filter((p) => p.updateAvailable).length,
    }), [pluginList]);

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

    function onFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file) return;

        const form = new FormData();
        form.append('plugin', file);

        setUploading(true);
        setError(null);

        router.post('/plugins/upload', form, {
            forceFormData: true,
            onError: (errors) => {
                setError(errors.plugin ?? 'Upload failed.');
                setUploading(false);
            },
            onFinish: () => {
                setUploading(false);
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    }

    function autoUpdateLabel(value: boolean | null): string {
        if (value === true) return 'On';
        if (value === false) return 'Off';
        return autoUpdateGloballyEnabled ? 'Default (on)' : 'Default (off)';
    }

    if (!canManagePlugins) {
        return (
            <div className="p-8 text-center text-gray-500">
                You do not have permission to manage plugins.
            </div>
        );
    }

    return (
        <div className="p-6">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">Plugins</h1>
                    <p className="text-sm text-gray-500">
                        Manage plugins for {auth.user?.name ?? 'this site'}.
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={checkUpdates}
                        className="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                    >
                        Check for updates
                    </button>
                    <button
                        onClick={() => fileInput.current?.click()}
                        disabled={uploading}
                        className="rounded-md bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {uploading ? 'Uploading…' : 'Upload plugin'}
                    </button>
                    <input
                        ref={fileInput}
                        type="file"
                        accept=".zip"
                        className="hidden"
                        onChange={onFileSelected}
                    />
                </div>
            </div>

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">
                    {error}
                </div>
            )}

            {updatesPending > 0 && (
                <div className="mb-4 rounded-md bg-yellow-50 px-4 py-2 text-sm text-yellow-800">
                    {updatesPending} update(s) available.
                </div>
            )}

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search by name, slug, description or author…"
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-sm w-72"
                />

                <div className="flex gap-1">
                    {([
                        ['all', 'All'],
                        ['active', 'Active'],
                        ['inactive', 'Inactive'],
                        ['updates', 'Updates'],
                    ] as [Filter, string][]).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setFilter(key)}
                            className={`rounded-md px-3 py-1.5 text-sm ${
                                filter === key
                                    ? 'bg-gray-900 text-white'
                                    : 'border border-gray-300 hover:bg-gray-50'
                            }`}
                        >
                            {label}
                            <span className="ml-1 text-xs opacity-70">{counts[key]}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {filtered.map((plugin) => (
                    <div key={plugin.slug} className="rounded-lg border border-gray-200 p-4 shadow-sm">
                        <div className="mb-2 flex items-start justify-between gap-2">
                            <div>
                                <h3 className="font-semibold">{plugin.name}</h3>
                                <p className="text-xs text-gray-500">{plugin.slug}</p>
                            </div>
                            <span
                                className={`rounded-full px-2 py-0.5 text-xs ${
                                    plugin.isActive
                                        ? 'bg-green-100 text-green-700'
                                        : 'bg-gray-100 text-gray-600'
                                }`}
                            >
                                {plugin.isActive ? 'Active' : 'Inactive'}
                            </span>
                        </div>

                        <p className="mb-3 line-clamp-2 text-sm text-gray-600">
                            {plugin.description}
                        </p>

                        {plugin.updateAvailable && plugin.latestVersion && (
                            <div className="mb-3 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800">
                                Version {plugin.latestVersion} available ({plugin.version} installed)
                            </div>
                        )}

                        <div className="mb-4 grid grid-cols-2 gap-1 text-xs text-gray-500">
                            <span>Version: {plugin.version}</span>
                            <span>Author: {plugin.author}</span>
                            <span>License: {plugin.license ?? '—'}</span>
                            <span>Requires PHP: {plugin.requiresPhp ?? '—'}</span>
                        </div>

                        <div className="flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                            <button
                                onClick={() => toggle(plugin)}
                                className={`rounded-md px-3 py-1.5 text-sm ${
                                    plugin.isActive
                                        ? 'border border-gray-300 hover:bg-gray-50'
                                        : 'bg-gray-900 text-white hover:bg-gray-800'
                                }`}
                            >
                                {plugin.isActive ? 'Deactivate' : 'Activate'}
                            </button>

                            {plugin.updateAvailable && (
                                <button
                                    onClick={() => update(plugin)}
                                    className="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700"
                                >
                                    Update now
                                </button>
                            )}

                            <button
                                onClick={() => cycleAutoUpdate(plugin)}
                                title="Cycle auto-update preference"
                                className="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                            >
                                Auto: {autoUpdateLabel(plugin.autoUpdate)}
                            </button>

                            {!plugin.isActive && (
                                <button
                                    onClick={() => trash(plugin)}
                                    className="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                                >
                                    Trash
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {filtered.length === 0 && (
                <div className="rounded-lg border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">
                    No plugins match your current search or filter.
                </div>
            )}

            {trashList.length > 0 && (
                <div className="mt-10">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Trash</h2>
                        <button
                            onClick={emptyTrash}
                            className="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                        >
                            Empty trash
                        </button>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                    <th className="px-4 py-2">Name</th>
                                    <th className="px-4 py-2">Version</th>
                                    <th className="px-4 py-2">Trashed at</th>
                                    <th className="px-4 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {trashList.map((item) => (
                                    <tr key={item.folder} className="border-t border-gray-100">
                                        <td className="px-4 py-2">
                                            <span className="font-medium">{item.name}</span>
                                            <span className="ml-2 text-xs text-gray-400">{item.slug}</span>
                                        </td>
                                        <td className="px-4 py-2">{item.version ?? '—'}</td>
                                        <td className="px-4 py-2">{item.trashedAt}</td>
                                        <td className="px-4 py-2 text-right">
                                            <button
                                                onClick={() => restore(item)}
                                                className="mr-2 rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50"
                                            >
                                                Restore
                                            </button>
                                            <button
                                                onClick={() => destroyPermanently(item)}
                                                className="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="mt-8 text-center text-xs text-gray-400">
                {counts.all} plugin(s) installed · max upload {maxUploadKb} KB
            </div>
        </div>
    );
}

import type { ThemeMode } from './types';

const STORAGE_KEY = 'color-scheme';

class ThemeManager {
	storedOverride = $state<'light' | 'dark' | null>(null);
	systemIsDark = $state(false);

	resolvedScheme = $derived<'light' | 'dark'>(
		this.storedOverride ?? (this.systemIsDark ? 'dark' : 'light')
	);

	selectedMode = $derived<ThemeMode>(this.storedOverride ?? 'system');

	init(): void {
		if (typeof window === 'undefined') return;

		const mq = window.matchMedia('(prefers-color-scheme: dark)');
		this.systemIsDark = mq.matches;

		mq.addEventListener('change', (e) => {
			// Update reactive OS tracking without deleting stored override (per modern-web-guidance)
			this.systemIsDark = e.matches;
		});

		try {
			const saved = localStorage.getItem(STORAGE_KEY);
			if (saved === 'light' || saved === 'dark') {
				this.storedOverride = saved;
			}
		} catch {
			// Ignore blocked storage
		}

		this.syncMetaTag();

		window.addEventListener('storage', (event) => {
			if (event.key === STORAGE_KEY) {
				const val = event.newValue;
				this.storedOverride = val === 'light' || val === 'dark' ? val : null;
				this.syncMetaTag();
			}
		});
	}

	/**
	 * Two-state comfort toggle for compact chrome:
	 * Switches to the opposite of currently rendered scheme.
	 * If target matches current OS preference, clears stored override so system tracking resumes.
	 */
	toggleComfort(): ThemeMode {
		const target: 'light' | 'dark' = this.resolvedScheme === 'dark' ? 'light' : 'dark';
		const currentSystem: 'light' | 'dark' = this.systemIsDark ? 'dark' : 'light';

		if (target === currentSystem) {
			this.setMode('system');
			return 'system';
		} else {
			this.setMode(target);
			return target;
		}
	}

	/**
	 * Explicit 3-state selector for Settings page ("system" | "light" | "dark").
	 */
	setMode(mode: ThemeMode): void {
		if (mode === 'system') {
			this.storedOverride = null;
			if (typeof window !== 'undefined') {
				try {
					localStorage.removeItem(STORAGE_KEY);
				} catch {
					// Ignore storage error
				}
			}
		} else {
			this.storedOverride = mode;
			if (typeof window !== 'undefined') {
				try {
					localStorage.setItem(STORAGE_KEY, mode);
				} catch {
					// Ignore storage error
				}
			}
		}
		this.syncMetaTag();
	}

	private syncMetaTag(): void {
		if (typeof document === 'undefined') return;
		let meta = document.querySelector('meta[name="color-scheme"]') as HTMLMetaElement | null;
		if (!meta) {
			meta = document.createElement('meta');
			meta.name = 'color-scheme';
			document.head.appendChild(meta);
		}
		meta.content = this.storedOverride ?? 'light dark';
	}
}

export const themeManager = new ThemeManager();


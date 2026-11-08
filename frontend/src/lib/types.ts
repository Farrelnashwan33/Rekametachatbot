export type ThemeMode = 'system' | 'light' | 'dark';
export type AssistantMode = 'general' | 'academic' | 'cv' | 'document' | 'creative' | 'coding';

export interface UserPreference {
	id: number;
	user_id: number;
	theme: ThemeMode;
	language: 'id' | 'en';
	communication_style: 'balanced' | 'concise' | 'detailed' | 'academic' | 'casual';
	memory_enabled: boolean;
	memory_auto_save: boolean;
	default_provider: string | null;
	default_model: string | null;
	voice_enabled: boolean;
}

export interface User {
	id: number;
	name: string;
	email: string;
	role: 'user' | 'admin';
	bio: string | null;
	daily_token_limit: number | null;
	is_active: boolean;
	last_login_at: string | null;
	created_at: string;
	preference?: UserPreference;
}

export interface AiModelOption {
	id: string;
	label: string;
	context_window?: number;
	supports_streaming?: boolean;
}

export interface PublicProviderInfo {
	provider_key: string;
	display_name: string;
	default_model: string;
	available_models: AiModelOption[];
	is_default: boolean;
	is_configured: boolean;
	supports_streaming: boolean;
}

export interface AiStatus {
	ai_ready: boolean;
	providers: PublicProviderInfo[];
}

export interface Conversation {
	id: number;
	uuid: string;
	user_id: number;
	title: string;
	assistant_mode: AssistantMode;
	provider_key: string | null;
	model_name: string | null;
	attached_document_ids: number[] | null;
	is_pinned: boolean;
	is_archived: boolean;
	last_message_at: string | null;
	created_at: string;
	updated_at: string;
}

export interface CitationItem {
	document_id: number;
	document_uuid?: string;
	title: string;
	chunk_index: number;
	heading?: string | null;
}

export interface Message {
	id: number;
	uuid: string;
	conversation_id: number;
	role: 'user' | 'assistant' | 'system';
	content: string;
	provider_key?: string | null;
	model_name?: string | null;
	prompt_tokens?: number;
	completion_tokens?: number;
	total_tokens?: number;
	latency_ms?: number | null;
	is_edited?: boolean;
	is_stopped?: boolean;
	feedback?: 'up' | 'down' | null;
	is_bookmarked?: boolean;
	citations?: CitationItem[] | null;
	created_at: string;
}

export interface UserMemory {
	id: number;
	user_id: number;
	source_conversation_id: number | null;
	category: 'preference' | 'profile' | 'goal' | 'project' | 'academic' | 'skill';
	memory_key: string;
	memory_value: string;
	importance: number;
	is_confirmed: boolean;
	is_active: boolean;
	last_used_at: string | null;
	updated_at: string;
}

export interface DocumentItem {
	id: number;
	uuid: string;
	title: string;
	original_filename: string;
	mime_type: string;
	extension: 'pdf' | 'docx' | 'txt' | 'csv';
	file_size: number;
	status: 'processing' | 'ready' | 'failed';
	structured_summary: {
		format?: string;
		page_count?: number | null;
		word_count?: number;
		detected_sections?: string[];
		excerpt?: string;
		csv_columns?: string[] | null;
		csv_rows?: number | null;
		ai_summary?: string | null;
		summarized_at?: string;
	} | null;
	page_count: number | null;
	word_count: number;
	chunk_count: number;
	error_message: string | null;
	created_at: string;
}

export interface CvExperience {
	role: string;
	company: string;
	location: string;
	start_date: string;
	end_date: string;
	highlights: string[];
}

export interface CvEducation {
	degree: string;
	institution: string;
	year: string;
	notes: string;
}

export interface CvCertification {
	name: string;
	issuer: string;
	year: string;
}

export interface CvProfile {
	id: number;
	uuid: string;
	title: string;
	template: 'executive' | 'ats_clean' | 'modern_minimal';
	full_name: string;
	headline: string | null;
	email: string | null;
	phone: string | null;
	location: string | null;
	website: string | null;
	linkedin: string | null;
	summary: string | null;
	experiences: CvExperience[];
	educations: CvEducation[];
	skills: string[];
	certifications: CvCertification[];
	ats_score: number | null;
	ats_evaluation: {
		score: number;
		readability_level: string;
		breakdown: Record<string, { score: number; max: number; label: string }>;
		strengths: string[];
		suggestions: string[];
		ai_review?: {
			professional_summary: string;
			executive_feedback: string;
			ats_keyword_tips: string[];
			bullet_improvements: { original: string; improved: string }[];
		};
	} | null;
	target_job_description: string | null;
	cover_letter: string | null;
	updated_at: string;
}

export interface AssistantCatalogItem {
	id: string;
	mode: AssistantMode;
	name: string;
	category: string;
	summary: string;
	route: string;
	prompts: {
		label: string;
		prompt: string;
		direct_route?: string;
	}[];
}

export interface AdminProvider {
	id: number;
	provider_key: string;
	display_name: string;
	driver: 'openai_compatible' | 'anthropic';
	base_url: string;
	default_model: string;
	available_models: AiModelOption[];
	is_enabled: boolean;
	is_default: boolean;
	supports_streaming: boolean;
	max_output_tokens: number;
	temperature: number;
	daily_token_limit_per_user: number;
	rate_limit_per_minute: number;
	has_api_key: boolean;
	masked_api_key: string | null;
	key_source: 'database_encrypted' | 'environment' | 'unconfigured';
}


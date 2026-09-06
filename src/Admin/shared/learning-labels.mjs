export const LEARNING_DOMAIN_LABELS = {
	site: 'Site',
	content: 'Content',
	developer: 'Developer',
	brand: 'Brand',
	seo: 'SEO',
	workflow: 'Workflow',
};
export const LEARNING_STATUS_LABELS = {
	pending: 'Pending',
	approved: 'Approved',
	dismissed: 'Dismissed',
};
export const LEARNING_CONFIDENCE_LABELS = {
	low: 'Low confidence',
	medium: 'Medium confidence',
	high: 'High confidence',
};
export function learningDomainLabel( domain ) {
	return LEARNING_DOMAIN_LABELS[ domain ] || 'Content';
}
export function learningStatusLabel( status ) {
	return LEARNING_STATUS_LABELS[ status ] || 'Pending';
}
export function learningConfidenceLabel( confidence ) {
	return LEARNING_CONFIDENCE_LABELS[ confidence ] || 'Medium confidence';
}

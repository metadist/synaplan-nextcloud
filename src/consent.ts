import type { Failure } from './failures'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { failureFromError } from './failures'

export interface ConsentAnswer {
	granted: boolean
	conflict: boolean
	failure: Failure | null
}

/**
 * Grant consent, and with createAccount have Synaplan create the account
 * first. A refused or failed request comes back as a failure to show, never
 * as a silent "still blocked".
 *
 * @param createAccount Whether the app should create the Synaplan account
 */
export async function grantConsent(createAccount = false): Promise<ConsentAnswer> {
	try {
		const { data } = await axios.post(
			generateUrl('/apps/synaplan_integration/api/v1/ai-consent'),
			createAccount
				? { granted: true, create_account: true }
				: { granted: true },
		)
		return {
			granted: !!data?.granted,
			conflict: !!data?.conflict,
			failure: null,
		}
	} catch (error) {
		return { granted: false, conflict: false, failure: failureFromError(error) }
	}
}

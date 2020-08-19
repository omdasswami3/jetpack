/**
 * Returns the failed Publicize connections.
 *
 * @param {Object} state State object.
 *
 * @return {Array} List of connections.
 */
export function getFailedConnections( state ) {
	return state.connections.filter( connection => false === connection.test_success );
}

/**
 * Returns a list of Publicize connection service names that require reauthentication from users.
 * iFor example, when LinkedIn switched its API from v1 to v2.
 *
 * @param {Object} state State object.
 *
 * @return {Array} List of service names that need reauthentication.
 */
export function getMustReauthConnections( state ) {
	return state.connections
		.filter( connection => 'must_reauth' === connection.test_success )
		.map( connection => connection.service_name );
}

export function getTweetStorm( state ) {
	const twitterAccount = state.connections.find(
		connection => 'twitter' === connection.service_name
	);

	const tweetTemplate = {
		date: Date.now(),
		name: 'Account Name',
		profileImage: 'https://abs.twimg.com/sticky/default_profile_images/default_profile_bigger.png',
		screenName: twitterAccount?.display_name || '',
	};

	return state.tweets.map( tweet => ( {
		...tweetTemplate,
		text: tweet.text,
		media: tweet.media,
	} ) );
}

export function getCurrentTweet( state ) {
	return state.tweets.reduce( ( currentTweet, tweet ) => {
		if ( currentTweet ) {
			return currentTweet;
		}

		if ( tweet.current ) {
			return tweet;
		}

		return false;
	}, false );
}

export function getTweetsForBlock( state, clientId ) {
	return state.tweets.filter( tweet => {
		if ( tweet.blocks.find( block => block.clientId === clientId ) ) {
			return true;
		}

		return false;
	} );
}

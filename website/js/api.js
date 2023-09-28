import { helpers } from "./helpers.js";
const api = {
    updateAvailabilityApi: function (roundId, playerId, present, drawnOut = false) {
        var url = "api/index.php/rounds/" + roundId + "/players/" + playerId;
        fetch(url, {
            method: 'POST',
            body: JSON.stringify({ present: present, drawnOut: drawnOut }),
            headers: {
                'Content-Type': 'application/json',
            }
        })
            .then(response => console.log('Success:', response))
            .catch((error) => {
                //show popup
                showErrorModal(['Er is iets misgelopen bij het updaten van de aanwezigheid van een speler.']);
            });
    },

    createMatch: function (roundId, match, button, onSuccess) {
        // create match in API
        // POST to /api/index.php/matches
        var url = "api/index.php/matches";
        fetch(url, {
            method: 'POST',
            body: JSON.stringify({
                roundId: roundId,
                player1Id: match["players"][0].id,
                player2Id: match["players"][1].id,
                player3Id: match["players"][2].id,
                player4Id: match["players"][3].id
            }),
            headers: {
                'Content-Type': 'application/json',
            }
        })
            .then(response => {
                if (response.status === 400) {
                    return response.json().then(error => {
                        // Handle the error response
                        throw new Error(error);
                    });
                } else {
                    // Process the successful response
                    return response.json();
                }
            })
            .then(data => {
                onSuccess(data, match, button);
            })
            .catch((error) => {
                //show popup
                helpers.showErrorModal([error.message]);
            });
    },
    updateMatch: function (roundId, set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match, onSuccess) {
        //save in the API
        // PUT to /api/index.php/matches/{matchId}
        var url = "api/index.php/matches/" + match["id"];
        fetch(url, {
            method: 'POST',
            body: JSON.stringify({
                roundId: roundId,
                set1Home: set1Home,
                set1Away: set1Away,
                set2Home: set2Home,
                set2Away: set2Away,
                set3Home: set3Home,
                set3Away: set3Away,
            }),
            headers: {
                'Content-Type': 'application/json',
            }
        })
            .then(response => {
                if (response.status === 400) {
                    return response.json().then(error => {
                        // Handle the error response
                        throw new Error(error);
                    });
                } else {
                    // Process the successful response
                    onSuccess(set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match);
                }
            })
            .catch((error) => {
                //show popup
                showErrorModal([error.message]);
            });

    }
}

export { api };
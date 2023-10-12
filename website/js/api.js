import { helpers } from "./helpers.js?v=20231012";
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

            .then(api.handleErrorResponse)
            .then(response => {
                // Process the successful response
                return response.json();
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
            .then(api.handleErrorResponse)
            .then(data => {
                onSuccess(set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match);

            })
            .catch((error) => {
                //show popup
                helpers.showErrorModal([error.message]);
            });

    },
    calculateRanking: function (onSuccess) {
        //calculate ranking
        // POST to /api/index.php/ranking
        var url = "api/index.php/seasons/calculate";
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
            .then(api.handleErrorResponse)
            .then(data => {
                onSuccess();
            })
            .catch((error) => {
                //show popup
                helpers.showErrorModal([error.message]);
            });
    },
    handleErrorResponse: function (response) {
        if (response.status === 500) {
            // Handle the error response
            throw new Error("Foutmelding op de server! Probeer opnieuw.");
        }
        else if (response.status === 400) {
            return response.json().then(error => {
                // Handle the error response
                throw new Error(error);
            });
        }
        else if (response.status === 401) {
            throw new Error("Je bent niet ingelogd. Log eerst in op <a href='https://www.bclandegem.be'" +
                "target='blank' >www.bclandegem.be</a> en probeer opnieuw.");
        } else {
            // Return the response if it's not an error
            return response;
        }
    }
}

export { api };
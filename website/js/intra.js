presentPlayers = [];
allPlayers = [];
drawnOutPlayers = [];
matches = [];
roundId = 0;
const addResultModalHtml = document.getElementById('addResultModal');
const addResultModal = new mdb.Modal(addResultModalHtml);

// window.onbeforeunload = function () {
//     return "Matchen en aangeduide spelers zullen verloren gaan.";
// };

function loadData() {
    const requestFetchAllPlayers = fetch('api/index.php/players');
    const requestFetchRanking = fetch('api/index.php/rankings/general');
    const requestFetchRound = fetch('api/index.php/rounds/latest');

    Promise.all([requestFetchAllPlayers, requestFetchRanking, requestFetchRound])
        .then(responses => {
            return Promise.all(responses.map(response => response.json()));
        })
        .then(data => {
            const data1 = data[0];
            const data2 = data[1];
            const roundData = data[2];
            roundId = roundData.id;
            allPlayers = data1;

            if (roundData.calculated == 1) {
                //TODO: Show new round popup
            }
            if (roundData.availabilityData) {
                roundData.availabilityData.forEach((playerPresentData) => {
                    if (playerPresentData.present == 1) {
                        //find player in allPlayers
                        const player = allPlayers.find(player => player.id === playerPresentData.playerId);
                        //add player to presentPlayers
                        presentPlayers.push(player);
                    }
                });

                updateAmountPlayersSpan();
            }
            if (roundData.matches) {
                roundData.matches.forEach((match) => {
                    const matchObject = [];
                    matchObject["id"] = match.id;
                    matchObject["players"] = [];
                    matchObject["players"].push(allPlayers.find(player => player.id === match.firstPlayer.id));
                    matchObject["players"].push(allPlayers.find(player => player.id === match.secondPlayer.id));
                    matchObject["players"].push(allPlayers.find(player => player.id === match.thirdPlayer.id));
                    matchObject["players"].push(allPlayers.find(player => player.id === match.fourthPlayer.id));
                    matchObject["set1Home"] = match.firstSet.home;
                    matchObject["set1Away"] = match.firstSet.away;
                    matchObject["set2Home"] = match.secondSet.home;
                    matchObject["set2Away"] = match.secondSet.away;
                    matchObject["set3Home"] = match.thirdSet.home;
                    matchObject["set3Away"] = match.thirdSet.away;
                    matches.push(matchObject);
                });
            }

            data2.general.forEach((item, index) => {
                const player = allPlayers.find(player => player.id === item.id);
                isPresent = false;
                //check if player is present
                presentPlayers.forEach(presentPlayer => {
                    if (presentPlayer.id == player.id) {
                        isPresent = true;
                    }
                });

                const rank = item.rank;
                const name = item.firstName + ' ' + item.name + ' (' + calculateBonusPoints(player) + ')';
                const points = item.points;

                const rankElement = document.createElement('div');
                rankElement.classList.add('grid-item');
                rankElement.textContent = rank;

                const nameElement = document.createElement('div');
                nameElement.classList.add('grid-item');
                nameElement.textContent = name;

                const pointsElement = document.createElement('div');
                pointsElement.classList.add('grid-item');
                pointsElement.textContent = points;


                const button = document.createElement("button");
                button.classList.add('btn', isPresent ? 'btn-success' : 'btn-primary', 'btn-lg', 'me-3');
                const icon = document.createElement('i');
                icon.classList.add('fa', isPresent ? 'fa-check' : 'fa-plus');
                button.appendChild(icon);
                if (isPresent) {
                    button.onclick = function () {
                        removePlayerPresent(item.id);
                        updateAbsentButton(button, item.id);
                    };
                }
                else {
                    button.onclick = function () {
                        addPlayerPresent(item.id);
                        updatePresentButton(button, item.id);
                    };
                }
                pointsElement.appendChild(button);
                const label = document.createElement('span');
                label.classList.add('badge', isPresent ? 'badge-success' : 'badge-primary');
                label.textContent = isPresent ? 'Is hier!' : 'Nog niet gezien';
                pointsElement.appendChild(label);

                document.querySelector('.grid-container').appendChild(rankElement);
                document.querySelector('.grid-container').appendChild(nameElement);
                document.querySelector('.grid-container').appendChild(pointsElement);
            });

            if (matches.length > 0) {
                displayMatches(matches);
            }
        })
        .catch(error => console.error(error));
}

function addPlayerPresent(id) {
    //find player in allPlayers
    const player = allPlayers.find(player => player.id === id);
    //add player to presentPlayers
    presentPlayers.push(player);
    updateAmountPlayersSpan();
    // POST to /api/index.php/rounds/{roundId}/players/{playerId}
    updateAvailabilityApi(player.id, true);

}

function updateAmountPlayersSpan() {
    const span = document.getElementById('playersPresent');
    span.textContent = "Aantal spelers: " + presentPlayers.length
}

function updateAvailabilityApi(playerId, present, drawnOut = false) {
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
}
function updatePresentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Is hier!';
    span.classList.remove('badge-primary');
    span.classList.add('badge-success');

    button.classList.remove('btn-primary');
    button.classList.add('btn-success');
    //update icon inside
    var icon = button.firstChild;
    icon.classList.remove('fa-plus');
    icon.classList.add('fa-check');

    button.appendChild(icon);
    button.onclick = function () {
        removePlayerPresent(id);
        updateAbsentButton(button, id);
    };

}
function updateAbsentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Nog niet gezien';
    span.classList.remove('badge-success');
    span.classList.add('badge-primary');
    button.classList.remove('btn-success');
    button.classList.add('btn-primary');
    var icon = button.firstChild;
    icon.classList.remove('fa-check');
    icon.classList.add('fa-plus');
    button.onclick = function () {
        addPlayerPresent(id);
        updatePresentButton(button, id);
    };
}
function removePlayerPresent(id) {
    //find player in presentPlayers
    const player = presentPlayers.find(player => player.id === id);
    //remove player from presentPlayers
    presentPlayers.splice(presentPlayers.indexOf(player), 1);
    updateAmountPlayersSpan();
    updateAvailabilityApi(player.id, false);
}


function generateMatches() {
    // Sort presentPlayers by rank
    presentPlayers.sort((a, b) => (a.rank > b.rank) ? 1 : -1);
    // Create two groups, which consist of 75% of the players
    // Overlap between those two groups is 50%
    const firstGroup = presentPlayers.slice(0, Math.floor(presentPlayers.length * 0.75));
    const secondGroup = presentPlayers.slice(Math.floor(presentPlayers.length * 0.25), presentPlayers.length);
    // Create matches
    matches = [];

    // Create match with 4 random players from first group
    // Then match with 4 random players from second group
    // Repeat until all players are matched (or less than 4 players left)
    // Don't forget to remove matched players from the groups

    while (firstGroup.length >= 4 || secondGroup.length >= 4) {
        if (firstGroup.length >= 4) {
            const match = [];
            match["players"] = [];
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * firstGroup.length);
                match["players"].push(firstGroup[randomIndex]);
                firstGroup.splice(randomIndex, 1);
            }
            matches.push(match);
            // remove matched players from second group
            match["players"].forEach(player => {
                const index = secondGroup.indexOf(player);
                if (index > -1) {
                    secondGroup.splice(index, 1);
                }
            });
        }
        if (secondGroup.length >= 4) {
            const match2 = [];
            match2["players"] = [];
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * secondGroup.length);
                match2["players"].push(secondGroup[randomIndex]);
                secondGroup.splice(randomIndex, 1);
            }
            matches.push(match2);
            // remove matched players from first group
            match2["players"].forEach(player => {
                const index = firstGroup.indexOf(player);
                if (index > -1) {
                    firstGroup.splice(index, 1);
                }
            });
        }
    }

    // If there are still players left, create a match with them
    if (firstGroup.length > 0 || secondGroup.length > 0) {
        // combine the two groups, but don't add the same player twice
        const remainingPlayers = firstGroup.concat(secondGroup).reduce((acc, current) => {
            const x = acc.find(item => item.id === current.id);
            if (!x) {
                return acc.concat([current]);
            } else {
                return acc;
            }
        }, []);

        // If there are more than 4 players left, pick 4 random players
        // Else pick all remaining players
        if (remainingPlayers.length >= 4) {
            const remainingPlayersMatch = [];
            remainingPlayersMatch["players"] = [];
            // Pick 4 random players from the remaining players
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * remainingPlayers.length);
                remainingPlayersMatch["players"].push(remainingPlayers[randomIndex]);
                remainingPlayers.splice(randomIndex, 1);
            }
            matches.push(remainingPlayersMatch);
        }
        if (remainingPlayers.length > 0) {
            // put all remaining players in the match
            const drawnOutMatch = [];
            drawnOutMatch["players"] = [];
            remainingPlayers.forEach(player => {
                drawnOutMatch["players"].push(player);
            });
            matches.push(drawnOutMatch);
            drawnOutPlayers = remainingPlayers;
        }
    }

    displayMatches(matches);
}
function displayMatches(matches) {
    // sort players now on name
    presentPlayers.sort((a, b) => (a.firstName > b.firstName) ? 1 : -1);
    generateMatchesHtml(matches);

    // hide playerlist
    document.getElementById('playerList').style.display = 'none';
    document.getElementById('matchList').style.display = '';
    // show toggle button
    document.getElementById('togglePlayerListButton').style.display = '';
    document.getElementById('togglePlayerListButton').textContent = 'Toon spelerslijst';
}

function generateMatchesHtml(matches) {
    // display matches
    const matchesContainer = document.getElementById('matchList');
    matchesContainer.innerHTML = '';
    matches.forEach((match, index) => {
        // create match container
        const firstTeamContainer = document.createElement('div');
        firstTeamContainer.classList.add('grid-item');
        // add first two players to match container
        displayPlayer(match["players"][0], firstTeamContainer);
        if (match["players"].length > 1)
            displayPlayer(match["players"][1], firstTeamContainer);
        else {
            displayPlayerDropdown(match, firstTeamContainer, 1);
        }
        matchesContainer.appendChild(firstTeamContainer);
        // create second team container
        const secondTeamContainer = document.createElement('div');
        secondTeamContainer.classList.add('grid-item');
        // add second two players to match container
        if (match["players"].length > 2)
            displayPlayer(match["players"][2], secondTeamContainer);
        else {
            displayPlayerDropdown(match, secondTeamContainer, 2);
        }
        if (match["players"].length > 3)
            displayPlayer(match["players"][3], secondTeamContainer);
        else {
            displayPlayerDropdown(match, secondTeamContainer, 3);
        }
        matchesContainer.appendChild(secondTeamContainer);

        // add results div to match container
        const resultDiv = document.createElement('div');
        resultDiv.classList.add('grid-item');
        //add button to result div
        const button = document.createElement("button");
        resultDiv.appendChild(button);
        matchesContainer.appendChild(resultDiv);
        if (match["id"]) {
            generateUpdateMatchHtml(button, match);
        }
        else {
            //clear classList
            while (button.classList.length > 0) {
                button.classList.remove(button.classList.item(0));
            }
            button.classList.add('btn', 'btn-success', 'btn-lg', 'me-3');

            button.innerHTML = '<i class="fa fa-check me-1"></i> Bevestig match';

            button.onclick = function () {
                const countNonEmptySpots = match["players"].reduce((count, obj) => {
                    if (obj !== undefined) {
                        return count + 1;
                    } else {
                        return count;
                    }
                }, 0);

                if (countNonEmptySpots < 4) {
                    showErrorModal(['Gelieve alle spelers in te vullen.']);
                    return;
                }
                createMatch(match, button);
            };
        }


    });
}

function createMatch(match, button) {
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

            match["id"] = data.id;

            generateUpdateMatchHtml(button, match);
        })
        .catch((error) => {
            //show popup
            showErrorModal([error.message]);
        });
};

function generateUpdateMatchHtml(button, match) {
    // once you start confirming matches: hide generate button
    document.getElementById('generateMatchesButton').style.display = 'none';
    // add sibling p next to button
    const p = document.createElement('p');
    // set id of p to matchId
    p.id = 'match-' + match.id;
    if (match["set1Home"]) {
        p.textContent = match["set1Home"] + '-' + match["set1Away"] + ' ' + match["set2Home"] + '-' + match["set2Away"] + ' '
            + match["set3Home"] + '-' + match["set3Away"];
    }
    button.parentNode.insertBefore(p, button.nextSibling);
    //clear classList
    while (button.classList.length > 0) {
        button.classList.remove(button.classList.item(0));
    }
    button.classList.add('btn', 'btn-primary', 'btn-lg', 'me-3');
    button.innerHTML = '<i class="fa fa-pencil me-1"></i> Vul resultaat in';
    button.onclick = function () {
        //add players to modal
        displayPlayerInModal(match["players"][0], 'set1Player1', 'set2Player1', 'set3Player1');
        displayPlayerInModal(match["players"][1], 'set1Player2', 'set2Player3', 'set3Player3');
        displayPlayerInModal(match["players"][2], 'set1Player3', 'set2Player2', 'set3Player4');
        displayPlayerInModal(match["players"][3], 'set1Player4', 'set2Player4', 'set3Player2');
        //add scores to modal
        document.getElementById('set1ScoreHome').value = match["set1Home"];
        document.getElementById('set1ScoreAway').value = match["set1Away"];
        document.getElementById('set2ScoreHome').value = match["set2Home"];
        document.getElementById('set2ScoreAway').value = match["set2Away"];
        document.getElementById('set3ScoreHome').value = match["set3Home"];
        document.getElementById('set3ScoreAway').value = match["set3Away"];
        //show modal
        addResultModal.show();
        //get save button
        const saveButton = document.getElementById('saveMatchButton');
        saveButton.onclick = function () {
            //Get value of every input
            const set1HomeValue = document.getElementById('set1ScoreHome').value;
            const set1Home = set1HomeValue ? parseInt(set1HomeValue) : 0;
            const set1AwayValue = document.getElementById('set1ScoreAway').value;
            const set1Away = set1AwayValue ? parseInt(set1AwayValue) : 0;
            const set2HomeValue = document.getElementById('set2ScoreHome').value;
            const set2Home = set2HomeValue ? parseInt(set2HomeValue) : 0;
            const set2AwayValue = document.getElementById('set2ScoreAway').value;
            const set2Away = set2AwayValue ? parseInt(set2AwayValue) : 0;
            const set3HomeValue = document.getElementById('set3ScoreHome').value;
            const set3Home = set3HomeValue ? parseInt(set3HomeValue) : 0;
            const set3AwayValue = document.getElementById('set3ScoreAway').value;
            const set3Away = set3AwayValue ? parseInt(set3AwayValue) : 0;
            updateMatch(set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match);

        }
    }
}
function updateMatch(set1Home, set1Away, set2Home, set2Away, set3Home, set3Away, match) {
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

                addResultModal.hide();
                match["set1Home"] = set1Home;
                match["set1Away"] = set1Away;
                match["set2Home"] = set2Home;
                match["set2Away"] = set2Away;
                match["set3Home"] = set3Home;
                match["set3Away"] = set3Away;
                // display result
                const p = document.getElementById('match-' + match["id"]);
                p.textContent = set1Home + '-' + set1Away + ' ' + set2Home + '-' + set2Away + ' ' + set3Home + '-' + set3Away;

            }
        })
        .catch((error) => {
            //show popup
            showErrorModal([error.message]);
        });

}

function displayPlayerInModal(player, pElementId1, pElementId2, pElementId3) {
    const playerElement1 = document.getElementById(pElementId1);
    playerElement1.textContent = player.firstName + ' ' + player.name;
    const playerElement2 = document.getElementById(pElementId2);
    playerElement2.textContent = player.firstName + ' ' + player.name;
    const playerElement3 = document.getElementById(pElementId3);
    playerElement3.textContent = player.firstName + ' ' + player.name;

}

function displayPlayer(player, container) {
    const playerElement = document.createElement('p');
    playerElement.textContent = player.firstName + ' ' + player.name + ' (' + calculateBonusPoints(player) + ')';
    container.appendChild(playerElement);
}
function displayPlayerDropdown(match, container, index) {
    const dropdownElement = document.createElement('p');
    const dropdown = document.createElement('select');
    //set width to 75%
    dropdown.style.width = '75%';
    dropdown.innerHTML = '';

    presentPlayers.forEach((player, index) => {
        const option = document.createElement('option');
        option.value = player.id;
        option.textContent = player.firstName + ' ' + player.name + ' (' + calculateBonusPoints(player) + ')';
        dropdown.appendChild(option);
    });
    dropdownElement.appendChild(dropdown);

    //Add Button
    const button = document.createElement("button");
    button.classList.add('btn', 'btn-primary', 'btn-lg', 'ms-3');
    button.innerHTML = '<i class="fa fa-check me-1"></i>';
    button.onclick = function () {
        console.log(dropdown.value);
        //find player in allPlayers
        const player = allPlayers.find(player => player.id === dropdown.value);
        match["players"][index] = player;
        //remove dropdownElement from container
        container.removeChild(dropdownElement);
        displayPlayer(player, container);
    };
    dropdownElement.appendChild(button);
    container.appendChild(dropdownElement);


}
function calculateBonusPoints(player) {
    var bonus = 0;
    if (player.gender === 'Woman') {
        bonus += 2;
    }
    if (player.playsCompetition == 0) {
        bonus += 5;
    }
    if (player.doubleRanking > 10) {
        bonus += 4;
    } else if (player.doubleRanking > 8) {
        bonus += 3;
    } else if (player.doubleRanking > 6) {
        bonus += 2;
    } else if (player.doubleRanking > 4) {
        bonus += 1;
    }
    return bonus;
}

function togglePlayerList() {
    const playerList = document.getElementById('playerList');
    if (playerList.style.display === 'none') {
        playerList.style.display = '';
        //change button text
        document.getElementById('togglePlayerListButton').textContent = 'Verberg spelerslijst';
        document.getElementById('matchList').style.display = 'none';
    } else {
        playerList.style.display = 'none';
        //change button text
        document.getElementById('togglePlayerListButton').textContent = 'Toon spelerslijst';
        document.getElementById('matchList').style.display = '';

    }
}

function showErrorModal(errors) {
    const errorModalHtml = document.getElementById('errorModal');
    const errorModal = new mdb.Modal(errorModalHtml);
    errorModalHtml.querySelector('.modal-body').innerHTML = '';
    errors.forEach(error => {
        //create p element and add error message
        const p = document.createElement('p');
        p.textContent = error;
        //add p element to modal
        errorModalHtml.querySelector('.modal-body').appendChild(p);
    });
    errorModal.show();
}

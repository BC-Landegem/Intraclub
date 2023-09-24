presentPlayers = [];
allPlayers = [];
drawnOutPlayers = [];
matches = [];

// window.onbeforeunload = function () {
//     return "Matchen en aangeduide spelers zullen verloren gaan.";
// };

function loadRanking() {
    fetch('https://www.bclandegem.be/intraclub-api/index.php/rankings/general')
        .then(response => response.json())
        .then(data => {
            allPlayers = data.general;
            data.general.forEach((item, index) => {
                const rank = item.rank;
                const name = item.firstName + ' ' + item.name;
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
                button.classList.add('btn', 'btn-primary', 'btn-lg', 'me-3');
                //add fa-check icon inside
                const icon = document.createElement('i');
                icon.classList.add('fa', 'fa-plus');
                button.appendChild(icon);
                button.onclick = function () {
                    addPlayerPresent(item.id);
                    updatePresentButton(button, item.id);
                };
                pointsElement.appendChild(button);
                const label = document.createElement('span');
                label.classList.add('badge', 'badge-primary');
                label.textContent = 'Nog niet gezien';
                pointsElement.appendChild(label);

                document.querySelector('.grid-container').appendChild(rankElement);
                document.querySelector('.grid-container').appendChild(nameElement);
                document.querySelector('.grid-container').appendChild(pointsElement);
            });
        })
        .catch(error => console.error(error));
}

function addPlayerPresent(id) {
    //find player in allPlayers
    const player = allPlayers.find(player => player.id === id);
    //add player to presentPlayers
    presentPlayers.push(player);
    const span = document.getElementById('playersPresent');
    span.textContent = "Aantal spelers: " + presentPlayers.length;
    //TODO: Update to API
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
    const span = document.getElementById('playersPresent');
    span.textContent = "Aantal spelers: " + presentPlayers.length;
    //TODO: Update API
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
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * firstGroup.length);
                match.push(firstGroup[randomIndex]);
                firstGroup.splice(randomIndex, 1);
            }
            matches.push(match);
            // remove matched players from second group
            match.forEach(player => {
                const index = secondGroup.indexOf(player);
                if (index > -1) {
                    secondGroup.splice(index, 1);
                }
            });
        }
        if (secondGroup.length >= 4) {
            const match2 = [];
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * secondGroup.length);
                match2.push(secondGroup[randomIndex]);
                secondGroup.splice(randomIndex, 1);
            }
            matches.push(match2);
            // remove matched players from first group
            match2.forEach(player => {
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
            // Pick 4 random players from the remaining players
            for (let i = 0; i < 4; i++) {
                const randomIndex = Math.floor(Math.random() * remainingPlayers.length);
                remainingPlayersMatch.push(remainingPlayers[randomIndex]);
                remainingPlayers.splice(randomIndex, 1);
            }
            matches.push(remainingPlayersMatch);
        }
        if (remainingPlayers.length > 0) {
            // put all remaining players in the match
            const drawnOutMatch = [];
            remainingPlayers.forEach(player => {
                drawnOutMatch.push(player);
            });
            matches.push(drawnOutMatch);
            drawnOutPlayers = remainingPlayers;
        }
    }
    displayMatches(matches);

    // hide playerlist
    document.getElementById('playerList').style.display = 'none';
    document.getElementById('matchList').style.display = '';
    // show toggle button
    document.getElementById('togglePlayerListButton').style.display = '';
    document.getElementById('togglePlayerListButton').textContent = 'Toon spelerslijst';

}

function displayMatches(matches) {
    // display matches
    const matchesContainer = document.getElementById('matchList');
    matchesContainer.innerHTML = '';
    matches.forEach((match, index) => {
        // create match container
        const firstTeamContainer = document.createElement('div');
        firstTeamContainer.classList.add('grid-item');
        // add first two players to match container
        displayPlayer(match[0], firstTeamContainer);
        if (match.length > 1)
            displayPlayer(match[1], firstTeamContainer);
        else {
            displayPlayerDropdown(firstTeamContainer);
        }
        matchesContainer.appendChild(firstTeamContainer);
        // create second team container
        const secondTeamContainer = document.createElement('div');
        secondTeamContainer.classList.add('grid-item');
        // add second two players to match container
        if (match.length > 2)
            displayPlayer(match[2], secondTeamContainer);
        else {
            displayPlayerDropdown(secondTeamContainer);
        }
        if (match.length > 3)
            displayPlayer(match[3], secondTeamContainer);
        else {
            displayPlayerDropdown(secondTeamContainer);
        }
        matchesContainer.appendChild(secondTeamContainer);

        // add results div to match container
        const resultDiv = document.createElement('div');
        resultDiv.classList.add('grid-item');
        //add button to result div
        const button = document.createElement("button");
        //add data-mdb-toggle="modal" data-mdb-target="#addPlayerModal"
        button.setAttribute('data-mdb-toggle', 'modal');
        button.setAttribute('data-mdb-target', '#addResultModal');
        button.classList.add('btn', 'btn-primary', 'btn-lg', 'me-3');
        button.innerHTML = '<i class="fa fa-pencil me-1"></i> Voeg resultaat toe';

        button.onclick = function () {
            //add players to modal
            displayPlayerInModal(matches[index][0], 'set1Player1', 'set2Player1', 'set3Player1');
            displayPlayerInModal(matches[index][1], 'set1Player2', 'set2Player3', 'set3Player3');
            displayPlayerInModal(matches[index][2], 'set1Player3', 'set2Player2', 'set3Player4');
            displayPlayerInModal(matches[index][3], 'set1Player4', 'set2Player4', 'set3Player2');


        };
        resultDiv.appendChild(button);
        matchesContainer.appendChild(resultDiv);

    });
}

//TODO: Fix "dropdown" players
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
    playerElement.textContent = player.firstName + ' ' + player.name;
    container.appendChild(playerElement);
}
function displayPlayerDropdown(container) {
    const dropdownElement = document.createElement('p');
    const dropdown = document.createElement('select');
    dropdown.innerHTML = '';
    presentPlayers.forEach((player, index) => {
        const option = document.createElement('option');
        option.value = player.id;
        option.textContent = player.firstName + ' ' + player.name;
        dropdown.appendChild(option);
    });
    dropdownElement.appendChild(dropdown);
    container.appendChild(dropdownElement);
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


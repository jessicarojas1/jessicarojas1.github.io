import SwiftUI
import SwiftData

struct NewMatchSetupView: View {
    @Environment(ActiveMatchManager.self) private var matchManager
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var format:       MatchFormat = .doubles
    @State private var gameLength:   GameLength  = .short
    @State private var bestOf:       Int         = 3
    @State private var team1Name:    String = ""
    @State private var team1Partner: String = ""
    @State private var team2Name:    String = ""
    @State private var team2Partner: String = ""

    private var canStart: Bool {
        !team1Name.trimmingCharacters(in: .whitespaces).isEmpty &&
        !team2Name.trimmingCharacters(in: .whitespaces).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Format") {
                    Picker("Match Type", selection: $format) {
                        ForEach(MatchFormat.allCases, id: \.self) { Text($0.rawValue).tag($0) }
                    }
                    .pickerStyle(.segmented)

                    Picker("Game Length", selection: $gameLength) {
                        ForEach(GameLength.allCases) { Text($0.label).tag($0) }
                    }
                    .pickerStyle(.segmented)

                    Picker("Best of", selection: $bestOf) {
                        Text("1 game").tag(1)
                        Text("Best of 3").tag(3)
                        Text("Best of 5").tag(5)
                    }
                    .pickerStyle(.segmented)
                }

                Section(format == .doubles ? "Team 1" : "Player 1") {
                    TextField("Name", text: $team1Name)
                    if format == .doubles {
                        TextField("Partner name", text: $team1Partner)
                    }
                }

                Section(format == .doubles ? "Team 2" : "Player 2") {
                    TextField("Name", text: $team2Name)
                    if format == .doubles {
                        TextField("Partner name", text: $team2Partner)
                    }
                }
            }
            .navigationTitle("New Match")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Start") {
                        let match = PBMatch(
                            format:       format,
                            gameLength:   gameLength.rawValue,
                            bestOf:       bestOf,
                            team1Name:    team1Name.trimmingCharacters(in: .whitespaces),
                            team1Partner: team1Partner.trimmingCharacters(in: .whitespaces),
                            team2Name:    team2Name.trimmingCharacters(in: .whitespaces),
                            team2Partner: team2Partner.trimmingCharacters(in: .whitespaces)
                        )
                        matchManager.startMatch(match, context: context)
                        dismiss()
                    }
                    .disabled(!canStart)
                    .fontWeight(.bold)
                }
            }
        }
    }
}

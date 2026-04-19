import SwiftUI

struct ClubProfileSetupView: View {
    @Environment(ClubProfile.self) private var profile

    var body: some View {
        @Bindable var p = profile
        List {
            Section {
                Toggle("Use my own distances", isOn: $p.useCustomDistances)
            } footer: {
                Text("When off, distances from the selected skill level are used for club recommendations during your round.")
            }

            if !profile.useCustomDistances {
                Section("Skill Level") {
                    ForEach(SkillLevel.allCases) { level in
                        LevelRow(level: level, isSelected: profile.selectedLevel == level) {
                            profile.selectedLevel = level
                        }
                    }
                }

                Section("Preview distances (\(profile.selectedLevel.rawValue))") {
                    ForEach(Club.allCases) { club in
                        HStack {
                            Text(club.emoji + "  " + club.rawValue)
                                .font(.callout)
                            Spacer()
                            Text("\(profile.selectedLevel.distances[club] ?? 0) yds")
                                .font(.callout.monospacedDigit())
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            } else {
                Section("My club distances (yards)") {
                    ForEach(Club.allCases) { club in
                        CustomDistanceRow(club: club, profile: profile)
                    }
                }
            }
        }
        .navigationTitle("Club Distances")
#if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
#endif
    }
}

// MARK: - Level Row

private struct LevelRow: View {
    let level: SkillLevel
    let isSelected: Bool
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(level.rawValue).font(.body)
                    Text(level.description)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Spacer()
                if isSelected {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                }
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Custom Distance Row

private struct CustomDistanceRow: View {
    let club: Club
    let profile: ClubProfile

    private var distance: Binding<Double> {
        Binding(
            get: { Double(profile.customDistances[club] ?? 100) },
            set: { profile.customDistances[club] = Int($0) }
        )
    }

    private var range: ClosedRange<Double> {
        switch club {
        case .driver:  return 80...350
        case .wood3:   return 70...310
        case .wood5:   return 60...280
        case .hybrid4: return 60...260
        case .iron4:   return 60...240
        case .iron5:   return 50...220
        case .iron6:   return 50...200
        case .iron7:   return 50...185
        case .iron8:   return 40...170
        case .iron9:   return 40...155
        case .pw:      return 30...145
        case .gw:      return 30...130
        case .sw:      return 20...115
        case .lw:      return 15...100
        }
    }

    var body: some View {
        VStack(spacing: 6) {
            HStack {
                Text(club.emoji + "  " + club.rawValue)
                    .font(.callout)
                Spacer()
                Text("\(profile.customDistances[club] ?? 100) yds")
                    .font(.callout.monospacedDigit().bold())
                    .frame(minWidth: 56, alignment: .trailing)
            }
            Slider(value: distance, in: range, step: 5)
                .tint(.green)
        }
        .padding(.vertical, 4)
    }
}

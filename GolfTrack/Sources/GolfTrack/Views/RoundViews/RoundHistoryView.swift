import SwiftUI
import SwiftData

struct RoundHistoryView: View {
    @Query(sort: \Round.date, order: .reverse) private var rounds: [Round]
    @Environment(\.modelContext) private var context
    @State private var searchText = ""

    private var completed: [Round] { rounds.filter { $0.isComplete } }

    private var filtered: [Round] {
        guard !searchText.isEmpty else { return completed }
        return completed.filter { $0.courseName.localizedCaseInsensitiveContains(searchText) }
    }

    private var grouped: [(String, [Round])] {
        let fmt = DateFormatter(); fmt.dateFormat = "MMMM yyyy"
        let dict = Dictionary(grouping: filtered) { fmt.string(from: $0.date) }
        return dict.sorted { ($0.1.first?.date ?? .distantPast) > ($1.1.first?.date ?? .distantPast) }
    }

    var body: some View {
        Group {
            if completed.isEmpty {
                ContentUnavailableView("No Rounds Yet", systemImage: "clock.arrow.circlepath",
                                       description: Text("Completed rounds will appear here."))
            } else {
                List {
                    ForEach(grouped, id: \.0) { month, items in
                        Section(month) {
                            ForEach(items) { round in
                                NavigationLink(destination: RoundDetailView(round: round)) {
                                    RoundRowView(round: round)
                                        .listRowInsets(.init())
                                }
                                .listRowBackground(Color.clear)
                            }
                            .onDelete { idx in idx.forEach { context.delete(items[$0]) } }
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .searchable(text: $searchText, prompt: "Search by course")
        .navigationTitle("History")
    }
}

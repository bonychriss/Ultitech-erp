
import { GoogleGenAI } from "@google/genai";
import { AttendanceRecord, Employee } from "./types";

const ai = new GoogleGenAI({ apiKey: process.env.API_KEY || "" });

export const getAttendanceInsights = async (
  records: AttendanceRecord[],
  employees: Employee[]
) => {
  try {
    const context = records.map(r => {
      const emp = employees.find(e => e.id === r.employeeId);
      return `Employee: ${emp?.name}, Date: ${r.date}, In: ${r.timeIn}, Out: ${r.timeOut}, Status: ${r.status}, OT: ${r.overtimeHours}`;
    }).join('\n');

    const response = await ai.models.generateContent({
      model: "gemini-3-flash-preview",
      contents: `
        Analyze the following attendance data and provide 3 key insights:
        1. Identify the most consistent employee.
        2. Identify any patterns of lateness.
        3. Suggest a productivity improvement based on overtime trends.
        
        Keep it professional and concise.
        
        Data:
        ${context}
      `,
    });

    return response.text || "No insights available at this time.";
  } catch (error) {
    console.error("Gemini Error:", error);
    return "Error generating AI insights. Please check API configuration.";
  }
};
